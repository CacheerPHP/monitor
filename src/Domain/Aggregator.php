<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Domain;

final class Aggregator
{
    public static function summarize(array $events): array
    {
        $stats = [
            'hits' => 0,
            'misses' => 0,
            'puts' => 0,
            'put_many' => 0,
            'clears' => 0,
            'flushes' => 0,
            'renews' => 0,
            'tags' => 0,
            'errors' => 0,
            'drivers' => [],
            'top_keys' => [],
            'namespaces' => [],
            'types' => [],
            'since' => null,
            'total_events' => count($events),
            'latency' => [ 'avg_ms' => 0.0, 'p95_ms' => 0.0, 'p99_ms' => 0.0 ],
        ];
        $latencies = [];

        foreach ($events as $e) {
            $type = $e['type'] ?? 'unknown';
            $payload = $e['payload'] ?? [];
            $driver = $payload['driver'] ?? 'unknown';
            $ts = $e['ts'] ?? null;
            if ($stats['since'] === null || ($ts && $ts < $stats['since'])) {
                $stats['since'] = $ts;
            }

            $stats['drivers'][$driver] = ($stats['drivers'][$driver] ?? 0) + 1;
            $stats['types'][$type] = ($stats['types'][$type] ?? 0) + 1;
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
        }

        $lookups = $stats['hits'] + $stats['misses'];
        $stats['hit_rate'] = $lookups > 0 ? ($stats['hits'] / $lookups) : 0.0;

        arsort($stats['top_keys']);
        $stats['top_keys'] = array_slice($stats['top_keys'], 0, 10, true);

        arsort($stats['drivers']);
        arsort($stats['namespaces']);
        arsort($stats['types']);

        foreach ($events as $e) {
            $d = $e['payload']['duration_ms'] ?? null;
            if (is_numeric($d)) { $latencies[] = (float) $d; }
        }
        if (!empty($latencies)) {
            sort($latencies);
            $n = count($latencies);
            $avg = array_sum($latencies) / $n;
            $percentile = function(float $q) use ($latencies, $n): float {
                $idx = (int) floor(($n - 1) * $q);
                return $latencies[$idx] ?? 0.0;
            };
            $stats['latency'] = [
                'avg_ms' => round($avg, 2),
                'p95_ms' => round($percentile(0.95), 2),
                'p99_ms' => round($percentile(0.99), 2),
            ];
        }

        return $stats;
    }
}

