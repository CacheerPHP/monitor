<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Domain;

/**
 * Aggregate metrics from raw event records.
 */
final class Aggregator
{
    private const LIFECYCLE_TYPES = ['stale_served', 'refresh', 'promotion', 'lock_contended'];

    /**
     * Compute summary statistics (hits, misses, rates, latency, TTL distribution, etc.).
     *
     * @param array<int,array<string,mixed>> $events
     * @return array<string,mixed>
     */
    public static function summarize(array $events, string $keyFilter = ''): array
    {
        $stats = [
            'hits'     => 0,
            'misses'   => 0,
            'puts'     => 0,
            'put_many' => 0,
            'clears'   => 0,
            'flushes'  => 0,
            'renews'   => 0,
            'tags'     => 0,
            'errors'   => 0,
            'drivers'      => [],
            'top_keys'     => [],
            'namespaces'   => [],
            'types'        => [],
            'since'        => null,
            'total_events' => count($events),
            'latency'      => ['avg_ms' => 0.0, 'p95_ms' => 0.0, 'p99_ms' => 0.0],
            'ttl_distribution' => self::emptyTtlBuckets(),
            'ttl_samples' => 0,
            'namespace_samples' => 0,
            'latency_samples' => 0,
            'lifecycle' => array_fill_keys(self::LIFECYCLE_TYPES, 0),
            'problem_keys' => [],
        ];
        $latencySamples = [];
        $keys = [];

        foreach ($events as $eventRecord) {
            $type    = $eventRecord['type']    ?? 'unknown';
            $payload = $eventRecord['payload'] ?? [];
            $driver  = $payload['driver']      ?? 'unknown';
            $ts      = $eventRecord['ts']      ?? null;

            if ($stats['since'] === null || ($ts && $ts < $stats['since'])) {
                $stats['since'] = $ts;
            }

            $stats['drivers'][$driver] = ($stats['drivers'][$driver] ?? 0) + 1;
            $stats['types'][$type]     = ($stats['types'][$type]     ?? 0) + 1;
            $ns = $payload['namespace'] ?? '';
            if ($ns === '') { $ns = '(default)'; }
            $stats['namespaces'][$ns] = ($stats['namespaces'][$ns] ?? 0) + 1;
            if (array_key_exists('namespace', $payload)) {
                $stats['namespace_samples']++;
            }
            if (array_key_exists($type, $stats['lifecycle'])) {
                $stats['lifecycle'][$type]++;
            }

            switch ($type) {
                case 'hit':
                    $stats['hits']++;
                    $key = $payload['key'] ?? null;
                    if ($key) { $stats['top_keys'][$key] = ($stats['top_keys'][$key] ?? 0) + 1; }
                    break;
                case 'miss':
                    $stats['misses']++;
                    break;
                case 'put':
                case 'put_forever':
                    $stats['puts']++;
                    break;
                case 'put_many':
                    $stats['put_many']++;
                    break;
                case 'clear':
                    $stats['clears']++;
                    break;
                case 'flush':
                    $stats['flushes']++;
                    break;
                case 'renew':
                    $stats['renews']++;
                    break;
                case 'tag':
                case 'flush_tag':
                    $stats['tags']++;
                    break;
                case 'error':
                    $stats['errors']++;
                    break;
            }

            // TTL distribution — only for write events that carry a ttl
            if (in_array($type, ['put', 'put_forever', 'add', 'renew'], true)) {
                // Missing TTL metadata is unknown, not a forever write.
                if ($type === 'put_forever' || array_key_exists('ttl', $payload)) {
                    $ttl = $payload['ttl'] ?? null;
                    self::recordTtlBucket($stats['ttl_distribution'], $type, $ttl);
                    $stats['ttl_samples']++;
                }
            }

            $durationMs = self::duration($eventRecord);
            if ($durationMs !== null) {
                $latencySamples[] = $durationMs;
            }

            $key = $payload['key'] ?? null;
            if (is_string($key) && $key !== '' && ($keyFilter === '' || stripos($key, $keyFilter) !== false)) {
                // Driver and namespace are part of identity: identical keys must not merge.
                $identity = json_encode([$driver, $payload['namespace'] ?? null, $key]);
                $keys[$identity] ??= [
                    'key' => $key, 'driver' => $driver, 'namespace' => $payload['namespace'] ?? null,
                    'hits' => 0, 'misses' => 0, 'errors' => 0, 'writes' => 0, 'events' => 0,
                    'durations' => [],
                ];
                $keys[$identity]['events']++;
                $counter = match ($type) {
                    'hit' => 'hits', 'miss' => 'misses', 'error' => 'errors',
                    'put', 'put_forever', 'put_many', 'add' => 'writes', default => null,
                };
                if ($counter !== null) {
                    $keys[$identity][$counter]++;
                }
                if ($durationMs !== null) {
                    $keys[$identity]['durations'][] = $durationMs;
                }
            }
        }

        $lookupCount       = $stats['hits'] + $stats['misses'];
        $stats['hit_rate'] = $lookupCount > 0 ? ($stats['hits'] / $lookupCount) : 0.0;

        arsort($stats['top_keys']);
        $stats['top_keys'] = array_slice($stats['top_keys'], 0, 10, true);

        arsort($stats['drivers']);
        arsort($stats['namespaces']);
        arsort($stats['types']);

        $stats['latency'] = self::latency($latencySamples);
        $stats['latency_samples'] = count($latencySamples);
        $rows = [];
        foreach ($keys as $keyStats) {
            $lookups = $keyStats['hits'] + $keyStats['misses'];
            $keyStats['hit_rate'] = $lookups > 0 ? $keyStats['hits'] / $lookups : null;
            $keyStats['latency'] = self::latency($keyStats['durations']);
            $keyStats['latency_samples'] = count($keyStats['durations']);
            unset($keyStats['durations']);
            $rows[] = $keyStats;
        }
        foreach (['misses', 'errors', 'hits', 'latency'] as $ranking) {
            $ranked = array_values(array_filter($rows, static fn ($row) => $ranking === 'latency'
                ? $row['latency_samples'] > 0 : $row[$ranking] > 0));
            usort($ranked, static function ($a, $b) use ($ranking): int {
                $aValue = $ranking === 'latency' ? $a['latency']['p95_ms'] : $a[$ranking];
                $bValue = $ranking === 'latency' ? $b['latency']['p95_ms'] : $b[$ranking];
                return ($bValue <=> $aValue) ?: ($b['events'] <=> $a['events'])
                    ?: ([$a['key'], $a['driver'], $a['namespace']] <=> [$b['key'], $b['driver'], $b['namespace']]);
            });
            $stats['problem_keys'][$ranking] = array_slice($ranked, 0, 10);
        }

        return $stats;
    }

    /**
     * Build a summary for a single key across a set of events.
     *
     * @param string                         $key
     * @param array<int,array<string,mixed>> $keyEvents  Events already filtered to this key
     * @return array<string,mixed>
     */
    public static function summarizeKey(string $key, array $keyEvents): array
    {
        $summary = [
            'key'                => $key,
            'hits'               => 0,
            'misses'             => 0,
            'puts'               => 0,
            'last_put_at'        => null,
            'last_hit_at'        => null,
            'last_miss_at'       => null,
            'last_ttl'           => null,
            'last_ttl_known'     => false,
            'last_size_bytes'    => null,
            'last_value_type'    => null,
            'last_value_preview' => null,
            'capture_values_enabled' => false,
            'preview_source'     => null,
            'namespaces'         => [],
            'drivers'            => [],
        ];

        // Separately track which timestamp produced the most recent value_preview
        // (can come from either a put OR a hit event)
        $lastPreviewTs = null;

        foreach ($keyEvents as $ev) {
            $type    = $ev['type']    ?? '';
            $payload = $ev['payload'] ?? [];
            $ts      = $ev['ts']      ?? null;

            $ns     = $payload['namespace'] ?? '(default)';
            $driver = $payload['driver']    ?? 'unknown';
            $summary['namespaces'][$ns]   = ($summary['namespaces'][$ns]   ?? 0) + 1;
            $summary['drivers'][$driver]  = ($summary['drivers'][$driver]  ?? 0) + 1;

            // --- hit ---
            if ($type === 'hit') {
                $summary['hits']++;
                if ($ts && ($summary['last_hit_at'] === null || $ts > $summary['last_hit_at'])) {
                    $summary['last_hit_at'] = $ts;
                }
            }

            // --- miss ---
            if ($type === 'miss') {
                $summary['misses']++;
                if ($ts && ($summary['last_miss_at'] === null || $ts > $summary['last_miss_at'])) {
                    $summary['last_miss_at'] = $ts;
                }
            }

            // --- write events: update put-specific metadata ---
            if (in_array($type, ['put', 'put_forever', 'add'], true)) {
                $summary['puts']++;
                if ($ts && ($summary['last_put_at'] === null || $ts > $summary['last_put_at'])) {
                    $summary['last_put_at']     = $ts;
                    $summary['last_ttl']        = $payload['ttl']        ?? null;
                    $summary['last_ttl_known']  = $type === 'put_forever' || array_key_exists('ttl', $payload);
                    $summary['last_size_bytes'] = $payload['size_bytes'] ?? null;
                    $summary['last_value_type'] = $payload['value_type'] ?? null;
                }
            }

            // --- value_preview: track from any event type (hit or write) ---
            // This ensures the inspector shows a preview even if the most recent
            // put was before CACHEER_MONITOR_CAPTURE_VALUES was enabled.
            if ($ts && ($lastPreviewTs === null || $ts > $lastPreviewTs)) {
                $preview = $payload['value_preview'] ?? null;
                if ($preview !== null) {
                    $summary['last_value_preview'] = $preview;
                    $summary['preview_source'] = 'event';
                    // Also update size/type from this event if they are more recent
                    if ($payload['size_bytes'] ?? null) {
                        $summary['last_size_bytes'] = $payload['size_bytes'];
                    }
                    if ($payload['value_type'] ?? null) {
                        $summary['last_value_type'] = $payload['value_type'];
                    }
                    $lastPreviewTs = $ts;
                }
            }
        }

        $lookupCount         = $summary['hits'] + $summary['misses'];
        $summary['hit_rate'] = $lookupCount > 0 ? ($summary['hits'] / $lookupCount) : null;
        $stats = self::summarize($keyEvents);
        $summary['lifecycle'] = $stats['lifecycle'];
        $summary['errors'] = $stats['errors'];
        $summary['latency'] = $stats['latency'];
        $summary['latency_samples'] = $stats['latency_samples'];
        $summary['namespace_samples'] = $stats['namespace_samples'];

        return $summary;
    }

    /** Timed operations only; lifecycle markers currently have no duration. */
    private static function duration(array $event): ?float
    {
        $duration = $event['payload']['duration_ms'] ?? null;
        if (in_array($event['type'] ?? '', self::LIFECYCLE_TYPES, true) || !is_numeric($duration)) {
            return null;
        }
        $duration = (float) $duration;
        return is_finite($duration) && $duration >= 0 ? $duration : null;
    }

    private static function latency(array $samples): array
    {
        if ($samples === []) {
            return ['avg_ms' => 0.0, 'p95_ms' => 0.0, 'p99_ms' => 0.0];
        }
        sort($samples);
        $percentile = static function (float $quantile) use ($samples): float {
            $position = (count($samples) - 1) * $quantile;
            $lower = (int) floor($position);
            $upper = (int) ceil($position);
            return $samples[$lower] + ($samples[$upper] - $samples[$lower]) * ($position - $lower);
        };
        return [
            'avg_ms' => round(array_sum($samples) / count($samples), 2),
            'p95_ms' => round($percentile(0.95), 2),
            'p99_ms' => round($percentile(0.99), 2),
        ];
    }

    /** Twenty buckets across the selected range, or the last ten minutes for All. */
    public static function timeline(array $events, float $from, float $until): array
    {
        $interval = max(1.0, ($until - $from) / 20);
        $buckets = [];
        for ($i = 0; $i < 20; $i++) {
            $buckets[] = ['ts' => $from + $i * $interval, 'hits' => 0, 'misses' => 0, 'sum' => 0.0, 'samples' => 0];
        }
        foreach ($events as $event) {
            $ts = $event['ts'] ?? null;
            if (!is_numeric($ts) || $ts < $from || $ts > $until) {
                continue;
            }
            $index = min(19, (int) floor(($ts - $from) / $interval));
            if (($event['type'] ?? '') === 'hit') { $buckets[$index]['hits']++; }
            if (($event['type'] ?? '') === 'miss') { $buckets[$index]['misses']++; }
            $duration = self::duration($event);
            if ($duration !== null) {
                $buckets[$index]['sum'] += $duration;
                $buckets[$index]['samples']++;
            }
        }
        foreach ($buckets as &$bucket) {
            $bucket['avg_ms'] = $bucket['samples'] > 0 ? round($bucket['sum'] / $bucket['samples'], 2) : null;
            unset($bucket['sum']);
        }
        unset($bucket);
        return ['from' => $from, 'until' => $until, 'interval_seconds' => $interval, 'buckets' => $buckets];
    }

    // -----------------------------------------------------------------------
    // TTL distribution helpers
    // -----------------------------------------------------------------------

    /**
     * @return array<string,int>
     */
    private static function emptyTtlBuckets(): array
    {
        return [
            'forever'  => 0,   // null TTL or PHP_INT_MAX
            'gt_1day'  => 0,   // > 86400 s
            'gt_1hour' => 0,   // > 3600 s
            'gt_5min'  => 0,   // > 300 s
            'gt_1min'  => 0,   // > 60 s
            'lte_1min' => 0,   // ≤ 60 s
        ];
    }

    /**
     * Increment the appropriate TTL bucket.
     *
     * @param array<string,int> $buckets
     * @param string            $type
     * @param mixed             $ttl
     */
    private static function recordTtlBucket(array &$buckets, string $type, mixed $ttl): void
    {
        if ($type === 'put_forever' || $ttl === null || (is_int($ttl) && $ttl >= PHP_INT_MAX / 2)) {
            $buckets['forever']++;
            return;
        }
        $seconds = is_numeric($ttl) ? (int) $ttl : 0;
        if ($seconds > 86400)     { $buckets['gt_1day']++;  return; }
        if ($seconds > 3600)      { $buckets['gt_1hour']++; return; }
        if ($seconds > 300)       { $buckets['gt_5min']++;  return; }
        if ($seconds > 60)        { $buckets['gt_1min']++;  return; }
        $buckets['lte_1min']++;
    }
}
