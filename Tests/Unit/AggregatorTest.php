<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Tests\Unit;

use Cacheer\Monitor\Domain\Aggregator;
use PHPUnit\Framework\TestCase;

class AggregatorTest extends TestCase
{
    public function test_summarize_empty_events(): void
    {
        $stats = Aggregator::summarize([]);

        $this->assertSame(0, $stats['hits']);
        $this->assertSame(0, $stats['misses']);
        $this->assertSame(0, $stats['total_events']);
        $this->assertSame(0.0, $stats['hit_rate']);
        $this->assertNull($stats['since']);
    }

    public function test_summarize_counts_hits_and_misses(): void
    {
        $events = [
            ['ts' => 1000.0, 'type' => 'hit', 'payload' => ['key' => 'a', 'driver' => 'file', 'duration_ms' => 1.5]],
            ['ts' => 1001.0, 'type' => 'hit', 'payload' => ['key' => 'b', 'driver' => 'file', 'duration_ms' => 2.0]],
            ['ts' => 1002.0, 'type' => 'miss', 'payload' => ['key' => 'c', 'driver' => 'file', 'duration_ms' => 0.5]],
        ];

        $stats = Aggregator::summarize($events);

        $this->assertSame(2, $stats['hits']);
        $this->assertSame(1, $stats['misses']);
        $this->assertSame(3, $stats['total_events']);
        $this->assertEqualsWithDelta(0.6667, $stats['hit_rate'], 0.01);
    }

    public function test_summarize_counts_puts_and_flushes(): void
    {
        $events = [
            ['ts' => 1.0, 'type' => 'put', 'payload' => ['driver' => 'redis']],
            ['ts' => 2.0, 'type' => 'put_forever', 'payload' => ['driver' => 'redis']],
            ['ts' => 3.0, 'type' => 'flush', 'payload' => ['driver' => 'redis']],
            ['ts' => 4.0, 'type' => 'clear', 'payload' => ['driver' => 'redis']],
            ['ts' => 5.0, 'type' => 'renew', 'payload' => ['driver' => 'redis']],
            ['ts' => 6.0, 'type' => 'tag', 'payload' => ['driver' => 'redis']],
            ['ts' => 7.0, 'type' => 'error', 'payload' => ['driver' => 'redis']],
        ];

        $stats = Aggregator::summarize($events);

        $this->assertSame(2, $stats['puts']);
        $this->assertSame(1, $stats['flushes']);
        $this->assertSame(1, $stats['clears']);
        $this->assertSame(1, $stats['renews']);
        $this->assertSame(1, $stats['tags']);
        $this->assertSame(1, $stats['errors']);
    }

    public function test_summarize_tracks_top_keys(): void
    {
        $events = [
            ['ts' => 1.0, 'type' => 'hit', 'payload' => ['key' => 'popular', 'driver' => 'file']],
            ['ts' => 2.0, 'type' => 'hit', 'payload' => ['key' => 'popular', 'driver' => 'file']],
            ['ts' => 3.0, 'type' => 'hit', 'payload' => ['key' => 'rare', 'driver' => 'file']],
        ];

        $stats = Aggregator::summarize($events);

        $this->assertSame(2, $stats['top_keys']['popular']);
        $this->assertSame(1, $stats['top_keys']['rare']);
    }

    public function test_summarize_normalizes_empty_namespace(): void
    {
        $events = [
            ['ts' => 1.0, 'type' => 'hit', 'payload' => ['namespace' => '', 'driver' => 'file']],
            ['ts' => 2.0, 'type' => 'hit', 'payload' => ['namespace' => 'api', 'driver' => 'file']],
        ];

        $stats = Aggregator::summarize($events);

        $this->assertSame(1, $stats['namespaces']['(default)']);
        $this->assertSame(1, $stats['namespaces']['api']);
    }

    public function test_summarize_tracks_drivers(): void
    {
        $events = [
            ['ts' => 1.0, 'type' => 'hit', 'payload' => ['driver' => 'file']],
            ['ts' => 2.0, 'type' => 'put', 'payload' => ['driver' => 'redis']],
            ['ts' => 3.0, 'type' => 'hit', 'payload' => ['driver' => 'file']],
        ];

        $stats = Aggregator::summarize($events);

        $this->assertSame(2, $stats['drivers']['file']);
        $this->assertSame(1, $stats['drivers']['redis']);
    }

    public function test_summarize_calculates_latency_percentiles(): void
    {
        // 20 samples: 1.0, 2.0, ..., 20.0
        $events = [];
        for ($i = 1; $i <= 20; $i++) {
            $events[] = ['ts' => (float) $i, 'type' => 'hit', 'payload' => ['driver' => 'file', 'duration_ms' => (float) $i]];
        }

        $stats = Aggregator::summarize($events);

        $this->assertSame(10.5, $stats['latency']['avg_ms']);
        $this->assertGreaterThan(18.0, $stats['latency']['p95_ms']);
        $this->assertGreaterThan(19.0, $stats['latency']['p99_ms']);
    }

    public function test_summarize_latency_with_single_sample(): void
    {
        $events = [
            ['ts' => 1.0, 'type' => 'hit', 'payload' => ['driver' => 'file', 'duration_ms' => 5.0]],
        ];

        $stats = Aggregator::summarize($events);

        $this->assertSame(5.0, $stats['latency']['avg_ms']);
        $this->assertSame(5.0, $stats['latency']['p95_ms']);
        $this->assertSame(5.0, $stats['latency']['p99_ms']);
    }

    public function test_summarize_since_tracks_earliest_timestamp(): void
    {
        $events = [
            ['ts' => 300.0, 'type' => 'hit', 'payload' => []],
            ['ts' => 100.0, 'type' => 'hit', 'payload' => []],
            ['ts' => 200.0, 'type' => 'hit', 'payload' => []],
        ];

        $stats = Aggregator::summarize($events);

        $this->assertSame(100.0, $stats['since']);
    }

    public function test_key_preview_metadata_stays_with_the_latest_captured_value(): void
    {
        $events = [
            ['ts' => 2.0, 'type' => 'hit', 'payload' => ['value_preview' => '{"value":123}', 'value_type' => 'array', 'size_bytes' => 42]],
            ['ts' => 1.0, 'type' => 'put', 'payload' => ['size_bytes' => 20]],
        ];

        foreach ([$events, array_reverse($events)] as $order) {
            $summary = Aggregator::summarizeKey('key', $order);
            $this->assertSame('{"value":123}', $summary['last_value_preview']);
            $this->assertSame('array', $summary['last_value_type']);
            $this->assertSame(42, $summary['last_size_bytes']);
        }
    }

    public function test_key_preview_metadata_does_not_fall_back_to_an_older_value(): void
    {
        $summary = Aggregator::summarizeKey('key', [
            ['ts' => 1.0, 'type' => 'put', 'payload' => ['value_preview' => '"old"', 'value_type' => 'string', 'size_bytes' => 20]],
            ['ts' => 2.0, 'type' => 'hit', 'payload' => ['value_preview' => 'null', 'size_bytes' => 0]],
        ]);

        $this->assertSame('null', $summary['last_value_preview']);
        $this->assertNull($summary['last_value_type']);
        $this->assertSame(0, $summary['last_size_bytes']);
    }

    public function test_increments_and_tag_flushes_are_counted_apart_from_writes(): void
    {
        $events = [
            ['ts' => 1.0, 'type' => 'increment', 'payload' => ['key' => 'views', 'driver' => 'redis', 'operation' => 'increment']],
            ['ts' => 2.0, 'type' => 'increment', 'payload' => ['key' => 'views', 'driver' => 'redis', 'operation' => 'increment']],
            ['ts' => 3.0, 'type' => 'flush_tag', 'payload' => ['driver' => 'redis', 'operation' => 'clearTag']],
            ['ts' => 4.0, 'type' => 'tag', 'payload' => ['key' => 'views', 'driver' => 'redis', 'operation' => 'tag']],
        ];

        $stats = Aggregator::summarize($events);

        $this->assertSame(2, $stats['increments']);
        $this->assertSame(1, $stats['tag_flushes']);
        $this->assertSame(1, $stats['tags']);
        $this->assertSame(0, $stats['puts']);
        $this->assertSame([], $stats['problem_keys']['hits'], 'Increments are not hits.');
    }

    public function test_loader_runs_are_summed_and_kept_out_of_cache_latency(): void
    {
        $events = [
            ['ts' => 1.0, 'type' => 'miss', 'payload' => ['key' => 'report', 'driver' => 'redis', 'duration_ms' => 0.5]],
            ['ts' => 1.1, 'type' => 'computed', 'payload' => ['key' => 'report', 'driver' => 'redis', 'duration_ms' => 840.0]],
            ['ts' => 1.2, 'type' => 'put', 'payload' => ['key' => 'report', 'driver' => 'redis', 'duration_ms' => 0.7]],
            ['ts' => 2.0, 'type' => 'fallback_served', 'payload' => ['driver' => 'ResilientStore', 'duration_ms' => 0.0]],
            ['ts' => 3.0, 'type' => 'reconciled', 'payload' => ['driver' => 'ResilientStore', 'duration_ms' => 25.0, 'count' => 1]],
        ];

        $stats = Aggregator::summarize($events);

        $this->assertSame(['count' => 1, 'total_ms' => 840.0], $stats['computed']);
        $this->assertSame(2, $stats['latency_samples'], 'Only the miss and the write are cache operations.');
        $this->assertEqualsWithDelta(0.6, $stats['latency']['avg_ms'], 0.001);

        $timeline = Aggregator::timeline($events, 0.0, 20.0);
        $this->assertSame(2, array_sum(array_column($timeline['buckets'], 'samples')));
    }

    public function test_reported_namespaces_and_ttls_feed_their_panels_and_unknown_stays_unknown(): void
    {
        $events = [
            ['ts' => 1.0, 'type' => 'put', 'payload' => ['key' => 'shop/a', 'driver' => 'redis', 'namespace' => 'shop', 'ttl' => 60]],
            ['ts' => 2.0, 'type' => 'put', 'payload' => ['key' => 'b', 'driver' => 'redis', 'namespace' => '', 'ttl' => null]],
            ['ts' => 3.0, 'type' => 'renew', 'payload' => ['key' => 'shop/a', 'driver' => 'redis', 'namespace' => 'shop', 'ttl' => 120]],
            ['ts' => 4.0, 'type' => 'put', 'payload' => ['key' => 'c', 'driver' => 'redis']],
        ];

        $stats = Aggregator::summarize($events);

        $this->assertSame(3, $stats['namespace_samples']);
        $this->assertSame(3, $stats['ttl_samples'], 'A write without a reported TTL is not counted as forever.');
        $this->assertSame(1, $stats['renews']);
        $this->assertSame(2, $stats['namespaces']['shop']);
    }

    public function test_failover_windows_come_from_fallback_activity_across_processes(): void
    {
        $events = [
            // First outage: two short-lived processes fail over; neither opens its circuit.
            ['ts' => 100.0, 'type' => 'fallback_served', 'instance' => 'fpm-1', 'payload' => ['driver' => 'ResilientStore', 'key' => 'a', 'count' => 1]],
            ['ts' => 110.0, 'type' => 'fallback_served', 'instance' => 'fpm-2', 'payload' => ['driver' => 'ResilientStore', 'count' => 5]],
            // A worker opens its circuit, later recovers and reconciles.
            ['ts' => 130.0, 'type' => 'circuit_opened', 'instance' => 'worker', 'payload' => ['driver' => 'ResilientStore', 'error' => 'Connection refused']],
            ['ts' => 140.0, 'type' => 'fallback_served', 'instance' => 'worker', 'payload' => ['driver' => 'ResilientStore', 'count' => 1]],
            ['ts' => 175.0, 'type' => 'reconciled', 'instance' => 'worker', 'payload' => ['driver' => 'ResilientStore', 'count' => 3]],
            ['ts' => 176.0, 'type' => 'circuit_closed', 'instance' => 'worker', 'payload' => ['driver' => 'ResilientStore']],
            // A second outage, more than a minute later, with no recovery seen yet.
            ['ts' => 400.0, 'type' => 'fallback_served', 'instance' => 'fpm-3', 'payload' => ['driver' => 'ResilientStore', 'count' => 2]],
        ];

        $failover = Aggregator::summarize($events)['failover'];

        $this->assertSame(9, $failover['fallback_served'], 'Operations served, counting each key of a batch.');
        $this->assertSame(1, $failover['circuit_opened']);
        $this->assertSame(1, $failover['circuit_closed']);
        $this->assertSame(1, $failover['reconciled']);
        $this->assertSame(3, $failover['invalidated']);
        $this->assertSame(2, $failover['window_count']);

        [$latest, $first] = $failover['windows'];
        $this->assertSame(400.0, $latest['start']);
        $this->assertSame(2, $latest['fallback_served']);
        $this->assertFalse($latest['recovered']);
        $this->assertNull($latest['error']);

        $this->assertSame(100.0, $first['start']);
        $this->assertSame(140.0, $first['end']);
        $this->assertSame(40.0, $first['duration_seconds']);
        $this->assertSame(7, $first['fallback_served']);
        $this->assertSame(3, $first['processes']);
        $this->assertSame('Connection refused', $first['error']);
        $this->assertTrue($first['recovered']);
    }

    public function test_no_failover_activity_reports_no_windows(): void
    {
        $failover = Aggregator::summarize([
            ['ts' => 1.0, 'type' => 'hit', 'payload' => ['key' => 'a', 'driver' => 'redis']],
        ])['failover'];

        $this->assertSame([], $failover['windows']);
        $this->assertSame(0, $failover['window_count']);
        $this->assertSame(0, $failover['fallback_served']);
    }
}
