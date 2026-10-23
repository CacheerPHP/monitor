<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Tests\Unit;

use Cacheer\Monitor\Domain\Aggregator;
use Cacheer\Monitor\Domain\KeyPattern;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return array<string, array{string, string}>
     */
    public static function keyShapes(): array
    {
        return [
            'numeric id'          => ['user:123', 'user:{id}'],
            'scoped numeric id'   => ['shop/product:42', 'shop/product:{id}'],
            'id inside a slug'    => ['product-42-reviews', 'product-{id}-reviews'],
            'query parameter'     => ['page=2&sort=name', 'page={id}&sort=name'],
            'uuid'                => ['order:550e8400-e29b-41d4-a716-446655440000', 'order:{uuid}'],
            'ulid'                => ['session:01ARZ3NDEKTSV4RRFFQ69G5FAV', 'session:{ulid}'],
            'date'                => ['report:2026-10-22', 'report:{date}'],
            'timestamp'           => ['report:2026-10-22T10:30:00Z', 'report:{date}'],
            'email'               => ['login:ada@example.com', 'login:{email}'],
            'hash'                => ['avatar:9f86d081884c7d659a2feaa0c55ad015', 'avatar:{hash}'],
            'number inside word'  => ['v2:config', 'v2:config'],
            'short hex word'      => ['color:deadbeef', 'color:deadbeef'],
            'constant'            => ['flag:ENABLED_FEATURE_FLAG_NUMBER1', 'flag:ENABLED_FEATURE_FLAG_NUMBER1'],
        ];
    }

    #[DataProvider('keyShapes')]
    public function test_keys_that_differ_only_by_an_identifier_share_a_pattern(string $key, string $pattern): void
    {
        $this->assertSame($pattern, KeyPattern::of($key));
    }

    public function test_patterns_group_keys_per_driver_and_namespace_and_rank_four_ways(): void
    {
        $events = [];
        // user:{id} in the shop namespace: 30 lookups, 6 hits — a cache that is not paying off.
        for ($i = 0; $i < 30; $i++) {
            $events[] = ['ts' => (float) $i, 'type' => $i % 5 === 0 ? 'hit' : 'miss', 'payload' => ['key' => 'shop/user:' . $i, 'driver' => 'redis', 'namespace' => 'shop', 'duration_ms' => 1.0]];
        }
        // product:{id}: 40 lookups, all hits.
        for ($i = 0; $i < 40; $i++) {
            $events[] = ['ts' => 100.0 + $i, 'type' => 'hit', 'payload' => ['key' => 'shop/product:' . ($i % 4), 'driver' => 'redis', 'namespace' => 'shop', 'duration_ms' => 0.2]];
        }
        // Same shape on another driver stays a separate pattern; too few lookups to rank by hit rate.
        $events[] = ['ts' => 200.0, 'type' => 'miss', 'payload' => ['key' => 'shop/user:1', 'driver' => 'file', 'namespace' => 'shop', 'duration_ms' => 9.0]];
        $events[] = ['ts' => 201.0, 'type' => 'put', 'payload' => ['key' => 'shop/user:1', 'driver' => 'file', 'namespace' => 'shop']];

        $patterns = Aggregator::summarize($events)['key_patterns'];

        $this->assertSame(3, $patterns['count']);
        $this->assertSame(Aggregator::PATTERN_MIN_LOOKUPS, $patterns['min_lookups']);

        $byHitRate = $patterns['rankings']['hit_rate'];
        $this->assertSame(['shop/user:{id}', 'shop/product:{id}'], array_column($byHitRate, 'pattern'), 'Lowest hit rate first, small samples left out.');
        $this->assertSame('redis', $byHitRate[0]['driver']);
        $this->assertSame(30, $byHitRate[0]['keys']);
        $this->assertSame(30, $byHitRate[0]['lookups']);
        $this->assertEqualsWithDelta(0.2, $byHitRate[0]['hit_rate'], 0.0001);
        $this->assertSame(['shop/user:0', 'shop/user:1', 'shop/user:2'], $byHitRate[0]['examples']);
        $this->assertSame(4, $byHitRate[1]['keys']);

        $this->assertSame(['shop/user:{id}', 'shop/user:{id}'], array_column($patterns['rankings']['misses'], 'pattern'));
        $this->assertSame(['redis', 'file'], array_column($patterns['rankings']['misses'], 'driver'));
        $this->assertSame('shop/product:{id}', $patterns['rankings']['lookups'][0]['pattern']);
        $this->assertSame('file', $patterns['rankings']['latency'][0]['driver']);
        $this->assertSame(1, $patterns['rankings']['latency'][0]['writes']);
    }

    public function test_key_search_narrows_patterns_like_it_narrows_problem_keys(): void
    {
        $events = [
            ['ts' => 1.0, 'type' => 'hit', 'payload' => ['key' => 'user:1', 'driver' => 'redis']],
            ['ts' => 2.0, 'type' => 'hit', 'payload' => ['key' => 'order:1', 'driver' => 'redis']],
        ];

        $patterns = Aggregator::summarize($events, 'order')['key_patterns'];

        $this->assertSame(['order:{id}'], array_column($patterns['rankings']['lookups'], 'pattern'));
        $this->assertNull($patterns['rankings']['lookups'][0]['namespace'], 'An unreported namespace stays unreported.');
    }
}
