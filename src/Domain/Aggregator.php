<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Domain;

/**
 * Aggregate metrics from raw event records.
 */
final class Aggregator
{
    /**
     * Compute summary statistics (hits, misses, rates, latency, TTL distribution, etc.).
     *
     * @param array<int,array<string,mixed>> $events
     * @return array<string,mixed>
     */
    public static function summarize(array $events): array
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
        ];
        $latencySamples = [];

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
                $ttl = $payload['ttl'] ?? null;
                self::recordTtlBucket($stats['ttl_distribution'], $type, $ttl);
            }
        }

        $lookupCount       = $stats['hits'] + $stats['misses'];
        $stats['hit_rate'] = $lookupCount > 0 ? ($stats['hits'] / $lookupCount) : 0.0;

        arsort($stats['top_keys']);
        $stats['top_keys'] = array_slice($stats['top_keys'], 0, 10, true);

        arsort($stats['drivers']);
        arsort($stats['namespaces']);
        arsort($stats['types']);

        foreach ($events as $eventRecord) {
            $durationMs = $eventRecord['payload']['duration_ms'] ?? null;
            if (is_numeric($durationMs)) {
                $latencySamples[] = (float) $durationMs;
            }
        }
        if (!empty($latencySamples)) {
            sort($latencySamples);
            $sampleCount  = count($latencySamples);
            $average      = array_sum($latencySamples) / $sampleCount;
            $percentileAt = function (float $quantile) use ($latencySamples, $sampleCount): float {
                $pos   = ($sampleCount - 1) * $quantile;
                $lower = (int) floor($pos);
                $upper = min((int) ceil($pos), $sampleCount - 1);
                if ($lower === $upper) {
                    return $latencySamples[$lower];
                }
                $fraction = $pos - $lower;
                return $latencySamples[$lower] * (1 - $fraction) + $latencySamples[$upper] * $fraction;
            };
            $stats['latency'] = [
                'avg_ms' => round($average, 2),
                'p95_ms' => round($percentileAt(0.95), 2),
                'p99_ms' => round($percentileAt(0.99), 2),
            ];
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

        return $summary;
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
