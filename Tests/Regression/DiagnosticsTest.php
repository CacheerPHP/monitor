<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Tests\Regression;

use Cacheer\Monitor\Domain\Aggregator;
use Cacheer\Monitor\Domain\EventStore;
use Cacheer\Monitor\Http\ApiController;
use Cacheer\Monitor\Http\Request;
use PHPUnit\Framework\TestCase;

final class DiagnosticsTest extends TestCase
{
    private function event(string $type, string $key = 'catalog', string $driver = 'RedisStore', array $extra = [], float $ts = 1000): array
    {
        return ['type' => $type, 'ts' => $ts, 'payload' => ['key' => $key, 'driver' => $driver] + $extra];
    }

    public function test_lifecycle_markers_do_not_dilute_timed_operation_latency(): void
    {
        $events = [$this->event('hit', extra: ['duration_ms' => 10])];
        foreach (['stale_served', 'refresh', 'promotion', 'lock_contended'] as $type) {
            $events[] = $this->event($type, extra: ['duration_ms' => 0]);
        }
        $stats = Aggregator::summarize($events);
        self::assertSame(1, $stats['latency_samples']);
        self::assertSame(10.0, $stats['latency']['avg_ms']);
        self::assertSame(['stale_served' => 1, 'refresh' => 1, 'promotion' => 1, 'lock_contended' => 1], $stats['lifecycle']);
        self::assertSame($stats['lifecycle'], Aggregator::summarizeKey('catalog', $events)['lifecycle']);
    }

    public function test_unknown_ttl_is_not_reported_as_forever(): void
    {
        $stats = Aggregator::summarize([
            $this->event('put'), $this->event('put', extra: ['ttl' => 30]),
            $this->event('put', extra: ['ttl' => null]), $this->event('put_forever'),
        ]);
        self::assertSame(3, $stats['ttl_samples']);
        self::assertSame(2, $stats['ttl_distribution']['forever']);
        self::assertSame(1, $stats['ttl_distribution']['lte_1min']);
        self::assertSame(0, $stats['namespace_samples']);
        self::assertFalse(Aggregator::summarizeKey('catalog', [$this->event('put')])['last_ttl_known']);
        self::assertTrue(Aggregator::summarizeKey('catalog', [$this->event('put_forever')])['last_ttl_known']);
    }

    public function test_rankings_include_miss_only_keys_and_keep_driver_and_namespace_identity(): void
    {
        $events = [
            $this->event('miss', extra: ['namespace' => 'shop', 'duration_ms' => 2]),
            $this->event('miss', extra: ['namespace' => 'shop', 'duration_ms' => 4]),
            $this->event('hit', extra: ['namespace' => 'admin', 'duration_ms' => 20]),
            $this->event('error', driver: 'FileStore', extra: ['namespace' => 'shop', 'duration_ms' => 100]),
            $this->event('hit', 'popular', extra: ['duration_ms' => 1]),
        ];
        $stats = Aggregator::summarize($events);
        $misses = $stats['problem_keys']['misses'][0];
        self::assertSame('shop', $misses['namespace']);
        self::assertSame('RedisStore', $misses['driver']);
        self::assertSame(2, $misses['misses']);
        self::assertEqualsWithDelta(0.0, $misses['hit_rate'], 0.0001);
        self::assertSame(2, $misses['latency_samples']);
        self::assertSame('FileStore', $stats['problem_keys']['errors'][0]['driver']);
        self::assertSame(100.0, $stats['problem_keys']['latency'][0]['latency']['p95_ms']);
        $filtered = Aggregator::summarize($events, 'POPULAR');
        self::assertSame([], $filtered['problem_keys']['misses']);
        self::assertCount(1, $filtered['problem_keys']['hits']);
        self::assertSame('popular', $filtered['problem_keys']['hits'][0]['key']);
        self::assertSame(5, $filtered['total_events']);
    }

    public function test_timeline_uses_entire_selected_range_and_excludes_outside_events(): void
    {
        $timeline = Aggregator::timeline([
            $this->event('hit', ts: 999), $this->event('miss', extra: ['duration_ms' => 4], ts: 1000),
            $this->event('stale_served', extra: ['duration_ms' => 0], ts: 1000),
            $this->event('hit', ts: 4600), $this->event('hit', ts: 4601),
        ], 1000, 4600);
        self::assertCount(20, $timeline['buckets']);
        self::assertSame(180.0, $timeline['interval_seconds']);
        self::assertSame(1, array_sum(array_column($timeline['buckets'], 'hits')));
        self::assertSame(1, $timeline['buckets'][0]['misses']);
        self::assertSame(4.0, $timeline['buckets'][0]['avg_ms']);
        self::assertSame(1, $timeline['buckets'][19]['hits']);
        self::assertNull($timeline['buckets'][1]['avg_ms']);
    }

    public function test_snapshot_metrics_are_independent_of_feed_limit_and_inspection_filters_driver(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'monitor-diagnostics-');
        $previous = getenv('CACHEER_MONITOR_EVENTS');
        try {
            $events = [
                $this->event('miss', extra: ['namespace' => 'quiet'], ts: 1000),
                $this->event('miss', extra: ['namespace' => 'quiet'], ts: 1001),
                $this->event('hit', driver: 'FileStore', ts: 1002),
                $this->event('hit', ts: 1003),
            ];
            file_put_contents($file, implode("\n", array_map('json_encode', $events)) . "\n");
            putenv('CACHEER_MONITOR_EVENTS=' . $file);
            $controller = ApiController::fromConfig();
            $snapshot = json_decode($controller->snapshot(new Request('GET', '/api/snapshot', ['limit' => '1', 'from' => '1000', 'until' => '1003']))->body, true);
            self::assertSame(4, $snapshot['metrics']['total_events']);
            self::assertCount(1, $snapshot['events']);
            self::assertSame(4, $snapshot['coverage']['matching_events']);
            self::assertSame(1, $snapshot['coverage']['shown_events']);
            self::assertSame(2, array_sum(array_column($snapshot['timeline']['buckets'], 'misses')));
            $filtered = json_decode($controller->snapshot(new Request('GET', '/api/snapshot', ['limit' => '1', 'type' => 'miss', 'key_filter' => 'CATALOG']))->body, true);
            self::assertSame(4, $filtered['metrics']['total_events']);
            self::assertCount(1, $filtered['events']);
            self::assertSame('miss', $filtered['events'][0]['type']);
            self::assertSame(1001, $filtered['events'][0]['ts']);
            self::assertSame(2, $filtered['coverage']['matching_feed_events']);
            self::assertCount(1, (new EventStore($file))->readAll(1, 'quiet'));
            self::assertSame(1001, (new EventStore($file))->readAll(1, 'quiet')[0]['ts']);
            $inspector = json_decode($controller->keyInspect(new Request('GET', '/api/keys/inspect', ['key' => 'catalog', 'driver' => 'FileStore']))->body, true);
            self::assertCount(1, $inspector['events']);
            self::assertSame(['FileStore' => 1], $inspector['summary']['drivers']);
            $unknown = json_decode($controller->keyInspect(new Request('GET', '/api/keys/inspect', ['key' => 'catalog', 'driver' => 'RedisStore', 'namespace_missing' => '1']))->body, true);
            self::assertCount(1, $unknown['events']);
            self::assertSame(0, $unknown['summary']['misses']);
            self::assertSame([], (new EventStore($file))->readAll(0, '(default)'));
            self::assertSame([], (new EventStore($file))->readByKey('catalog', '(default)'));
        } finally {
            $previous === false ? putenv('CACHEER_MONITOR_EVENTS') : putenv('CACHEER_MONITOR_EVENTS=' . $previous);
            @unlink($file);
        }
    }
}
