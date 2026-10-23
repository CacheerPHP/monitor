<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Domain;

/**
 * Hits, misses, and average operation latency in 20 equal buckets between two
 * times, built one event at a time so it can share a single pass over the log.
 */
final class Timeline
{
    private const BUCKETS = 20;

    private float $interval;

    /** @var list<array{ts: float, hits: int, misses: int, sum: float, samples: int}> */
    private array $buckets = [];

    public function __construct(private readonly float $from, private readonly float $until)
    {
        $this->interval = max(1.0, ($until - $from) / self::BUCKETS);
        for ($i = 0; $i < self::BUCKETS; $i++) {
            $this->buckets[] = ['ts' => $from + $i * $this->interval, 'hits' => 0, 'misses' => 0, 'sum' => 0.0, 'samples' => 0];
        }
    }

    /**
     * @param array<string,mixed> $event
     */
    public function add(array $event): void
    {
        $ts = $event['ts'] ?? null;
        if (!is_numeric($ts) || $ts < $this->from || $ts > $this->until) {
            return;
        }

        $index = min(self::BUCKETS - 1, (int) floor(($ts - $this->from) / $this->interval));
        $type = $event['type'] ?? '';
        if ($type === 'hit') {
            $this->buckets[$index]['hits']++;
        }
        if ($type === 'miss') {
            $this->buckets[$index]['misses']++;
        }

        $duration = Aggregator::operationDuration($event);
        if ($duration !== null) {
            $this->buckets[$index]['sum'] += $duration;
            $this->buckets[$index]['samples']++;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $buckets = [];
        foreach ($this->buckets as $bucket) {
            $bucket['avg_ms'] = $bucket['samples'] > 0 ? round($bucket['sum'] / $bucket['samples'], 2) : null;
            unset($bucket['sum']);
            $buckets[] = $bucket;
        }

        return ['from' => $this->from, 'until' => $this->until, 'interval_seconds' => $this->interval, 'buckets' => $buckets];
    }
}
