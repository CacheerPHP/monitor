<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Tests\Unit;

use Cacheer\Monitor\Http\ApiController;
use Cacheer\Monitor\Http\Request;
use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Observability\Telemetry;
use Silviooosilva\CacheerPhp\Observability\CacheEvent;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Storage\EnvelopeCodec;
use Silviooosilva\CacheerPhp\Storage\Serializer\JsonSerializer;
use Silviooosilva\CacheerPhp\Stores\FileStore;

class ApiControllerTest extends TestCase
{
    private string $eventsFile;
    private string $cacheDir;
    private string|false $originalEventsFile;
    private string|false $originalCaptureValues;
    private string|false $originalCachePath;

    protected function setUp(): void
    {
        $this->eventsFile = sys_get_temp_dir() . '/cacheer-monitor-api-test-' . bin2hex(random_bytes(4)) . '.jsonl';
        $this->originalEventsFile = getenv('CACHEER_MONITOR_EVENTS');
        $this->originalCaptureValues = getenv('CACHEER_MONITOR_CAPTURE_VALUES');

        putenv('CACHEER_MONITOR_EVENTS=' . $this->eventsFile);
        putenv('CACHEER_MONITOR_CAPTURE_VALUES=true');

        $this->cacheDir = sys_get_temp_dir() . '/cacheer-monitor-api-cache-' . bin2hex(random_bytes(4));
        @mkdir($this->cacheDir, 0777, true);
        $this->originalCachePath = getenv('CACHEER_MONITOR_CACHE_PATH');
        putenv('CACHEER_MONITOR_CACHE_PATH=' . $this->cacheDir);

        Telemetry::reset();
    }

    protected function tearDown(): void
    {
        Telemetry::reset();

        if ($this->originalEventsFile === false) {
            putenv('CACHEER_MONITOR_EVENTS');
        } else {
            putenv('CACHEER_MONITOR_EVENTS=' . $this->originalEventsFile);
        }

        if ($this->originalCaptureValues === false) {
            putenv('CACHEER_MONITOR_CAPTURE_VALUES');
        } else {
            putenv('CACHEER_MONITOR_CAPTURE_VALUES=' . $this->originalCaptureValues);
        }

        if ($this->originalCachePath === false) {
            putenv('CACHEER_MONITOR_CACHE_PATH');
        } else {
            putenv('CACHEER_MONITOR_CACHE_PATH=' . $this->originalCachePath);
        }

        if (is_file($this->eventsFile)) {
            @unlink($this->eventsFile);
        }

        $this->removeDir($this->cacheDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach ((array) glob($dir . '/*') as $path) {
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    public function test_key_inspector_falls_back_to_live_cache_value_when_no_preview_was_recorded(): void
    {
        Cacheer::file($this->cacheDir)->set('preview-key', ['name' => 'Cacheer', 'enabled' => true]);

        file_put_contents($this->eventsFile, json_encode([
            'ts' => microtime(true),
            'type' => 'put',
            'payload' => [
                'key' => 'preview-key',
                'namespace' => '',
                'driver' => 'FileStore',
                'value_type' => 'array',
                'size_bytes' => 32,
            ],
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL);

        $controller = ApiController::fromConfig();
        $response = $controller->keyInspect(new Request('GET', '/api/keys/inspect', ['key' => 'preview-key']));
        $decoded = json_decode($response->body, true);

        $this->assertTrue($decoded['summary']['capture_values_enabled']);
        $this->assertSame('live', $decoded['summary']['preview_source']);
        $this->assertStringContainsString('"name":"Cacheer"', $decoded['summary']['last_value_preview']);
        $this->assertSame('array', $decoded['summary']['last_value_type']);
    }

    public function test_cleanup_rotated_requires_token_when_configured(): void
    {
        $originalToken = getenv('CACHEER_MONITOR_TOKEN');
        $originalHeader = $_SERVER['HTTP_X_MONITOR_TOKEN'] ?? null;
        putenv('CACHEER_MONITOR_TOKEN=s3cret');
        unset($_SERVER['HTTP_X_MONITOR_TOKEN']);

        try {
            $controller = ApiController::fromConfig();

            // Missing token header → 401
            $denied = $controller->cleanupRotated(new Request('POST', '/api/events/cleanup-rotated', []));
            $this->assertSame(401, $denied->status);

            // Correct token header → allowed
            $_SERVER['HTTP_X_MONITOR_TOKEN'] = 's3cret';
            $allowed = $controller->cleanupRotated(new Request('POST', '/api/events/cleanup-rotated', []));
            $this->assertSame(200, $allowed->status);
            $this->assertTrue(json_decode($allowed->body, true)['ok']);
        } finally {
            if ($originalToken === false) {
                putenv('CACHEER_MONITOR_TOKEN');
            } else {
                putenv('CACHEER_MONITOR_TOKEN=' . $originalToken);
            }
            if ($originalHeader === null) {
                unset($_SERVER['HTTP_X_MONITOR_TOKEN']);
            } else {
                $_SERVER['HTTP_X_MONITOR_TOKEN'] = $originalHeader;
            }
        }
    }

    public function test_snapshot_returns_metrics_and_events_from_single_read(): void
    {
        $lines = '';
        foreach (
            [
                ['type' => 'hit',  'payload' => ['key' => 'a', 'driver' => 'FileCacheStore', 'duration_ms' => 1.0]],
                ['type' => 'miss', 'payload' => ['key' => 'b', 'driver' => 'FileCacheStore', 'duration_ms' => 2.0]],
                ['type' => 'hit',  'payload' => ['key' => 'a', 'driver' => 'FileCacheStore', 'duration_ms' => 0.5]],
            ] as $i => $event
        ) {
            $lines .= json_encode(['ts' => 1000.0 + $i, 'type' => $event['type'], 'payload' => $event['payload']]) . PHP_EOL;
        }
        file_put_contents($this->eventsFile, $lines);

        $controller = ApiController::fromConfig();
        $response = $controller->snapshot(new Request('GET', '/api/snapshot', []));
        $decoded = json_decode($response->body, true);

        $this->assertArrayHasKey('metrics', $decoded);
        $this->assertArrayHasKey('events', $decoded);
        $this->assertSame(2, $decoded['metrics']['hits']);
        $this->assertSame(1, $decoded['metrics']['misses']);
        $this->assertCount(3, $decoded['events']);
        // metrics are aggregated over the same set returned as events
        $this->assertSame($decoded['metrics']['total_events'], count($decoded['events']));
    }

    public function test_snapshot_feed_holds_the_newest_matching_events_by_timestamp(): void
    {
        // Two processes' batches landed out of time order; 30 events in all.
        $lines = '';
        foreach ([[20, 30, 'p2'], [0, 20, 'p1']] as [$first, $last, $instance]) {
            for ($i = $first; $i < $last; $i++) {
                $lines .= json_encode([
                    'ts' => 1000.0 + $i,
                    'type' => $i % 3 === 0 ? 'miss' : 'hit',
                    'instance' => $instance,
                    'payload' => ['key' => 'user:' . $i, 'driver' => 'FileStore', 'duration_ms' => 1.0],
                ]) . PHP_EOL;
            }
        }
        file_put_contents($this->eventsFile, $lines);

        $snapshot = $this->snapshotWith(['limit' => '5', 'type' => 'miss']);

        // The misses are 0, 3, ..., 27; the newest five, oldest first.
        $this->assertSame(
            ['user:15', 'user:18', 'user:21', 'user:24', 'user:27'],
            array_map(static fn (array $event): string => $event['payload']['key'], $snapshot['events']),
        );
        $this->assertSame(['source' => 'current_log', 'matching_events' => 30, 'matching_feed_events' => 10, 'shown_events' => 5], $snapshot['coverage']);
        $this->assertSame(30, $snapshot['metrics']['total_events'], 'Metrics cover every matching record, not just the feed.');
        $this->assertSame(10, $snapshot['metrics']['misses']);
    }

    public function test_snapshot_feed_without_a_limit_returns_every_match_in_time_order(): void
    {
        file_put_contents($this->eventsFile, implode(PHP_EOL, array_map('json_encode', [
            ['ts' => 30.0, 'type' => 'hit', 'payload' => ['key' => 'late', 'driver' => 'FileStore']],
            ['ts' => 10.0, 'type' => 'hit', 'payload' => ['key' => 'early', 'driver' => 'FileStore']],
            ['ts' => 20.0, 'type' => 'miss', 'payload' => ['key' => 'middle', 'driver' => 'FileStore']],
        ])) . PHP_EOL);

        $snapshot = $this->snapshotWith(['limit' => '0', 'from' => '0', 'until' => '100']);

        $this->assertSame(['early', 'middle', 'late'], array_map(static fn (array $event): string => $event['payload']['key'], $snapshot['events']));
        $this->assertSame(2, array_sum(array_column($snapshot['timeline']['buckets'], 'hits')), 'The timeline is built in the same pass.');
        $this->assertSame(1, $snapshot['timeline']['buckets'][4]['misses'], 'ts 20 lands in the fifth 5-second bucket.');
    }

    public function test_key_inspector_can_force_live_refresh_over_existing_event_preview(): void
    {
        Cacheer::file($this->cacheDir)->set('preview-key-live', ['name' => 'Fresh Value']);

        file_put_contents($this->eventsFile, json_encode([
            'ts' => microtime(true),
            'type' => 'put',
            'payload' => [
                'key' => 'preview-key-live',
                'namespace' => '',
                'driver' => 'FileStore',
                'value_type' => 'array',
                'size_bytes' => 24,
                'value_preview' => '{"name":"Stale Value"}',
            ],
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL);

        $controller = ApiController::fromConfig();
        $response = $controller->keyInspect(new Request('GET', '/api/keys/inspect', [
            'key' => 'preview-key-live',
            'live' => '1',
        ]));
        $decoded = json_decode($response->body, true);

        $this->assertSame('live', $decoded['summary']['preview_source']);
        $this->assertStringContainsString('"name":"Fresh Value"', $decoded['summary']['last_value_preview']);
        $this->assertStringNotContainsString('Stale Value', $decoded['summary']['last_value_preview']);
    }

    public function test_scoped_live_preview_is_redacted_and_does_not_emit_cache_events(): void
    {
        Cacheer::file($this->cacheDir)->scope('catalog')->scope('venues')->set('spaces', [
            'name' => 'Meeting room', 'password' => 'do-not-expose',
        ]);
        $this->recordKey('catalog/venues/spaces');
        $events = [];
        Telemetry::listen(static function (CacheEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $summary = $this->inspectKey('catalog/venues/spaces');

        $this->assertSame('live', $summary['preview_source']);
        $this->assertStringContainsString('Meeting room', $summary['last_value_preview']);
        $this->assertStringContainsString('[redacted]', $summary['last_value_preview']);
        $this->assertStringNotContainsString('do-not-expose', $summary['last_value_preview']);
        $this->assertSame([], $events);
        $this->assertTrue(Telemetry::hasListeners());

        $missing = $this->inspectKey('missing-key');
        $this->assertNull($missing['last_value_preview']);
        $this->assertStringContainsString('not found', $missing['live_lookup_reason']);
        $this->assertSame([], $events);
    }

    public function test_live_preview_rejects_ambiguous_root_and_scoped_display_names(): void
    {
        $cache = Cacheer::file($this->cacheDir);
        $cache->set('catalog/spaces', 'root value');
        $cache->scope('catalog')->set('spaces', 'scoped value');
        $this->recordKey('catalog/spaces');

        $summary = $this->inspectKey('catalog/spaces');

        $this->assertNull($summary['last_value_preview']);
        $this->assertStringContainsString('Multiple stored keys', $summary['live_lookup_reason']);
    }

    public function test_recorded_root_namespace_disambiguates_a_literal_slash_key(): void
    {
        $cache = Cacheer::file($this->cacheDir);
        $cache->set('catalog/spaces', 'root value');
        $cache->scope('catalog')->set('spaces', 'scoped value');
        $this->recordKey('catalog/spaces', ['namespace' => '']);

        $summary = $this->inspectKey('catalog/spaces');

        $this->assertSame('"root value"', $summary['last_value_preview']);
        $this->assertSame('live', $summary['preview_source']);
    }

    public function test_live_preview_preserves_falsy_values_and_accepts_a_driver_without_history(): void
    {
        $cache = Cacheer::file($this->cacheDir);
        foreach ([null, false, 0, ''] as $i => $value) {
            $key = 'falsy-' . $i;
            $cache->set($key, $value);
            $summary = $this->inspectKey($key);

            $this->assertSame('live', $summary['preview_source']);
            $this->assertSame(json_encode($value), $summary['last_value_preview']);
            $this->assertSame(gettype($value), $summary['last_value_type']);
        }
    }

    public function test_failed_live_refresh_retains_the_captured_preview_with_a_reason(): void
    {
        $this->recordKey('removed-key', ['value_preview' => '"captured"', 'value_type' => 'string', 'size_bytes' => 15]);

        $summary = $this->inspectKey('removed-key', ['live' => '1']);

        $this->assertSame('"captured"', $summary['last_value_preview']);
        $this->assertSame('event', $summary['preview_source']);
        $this->assertSame('string', $summary['last_value_type']);
        $this->assertSame(15, $summary['last_size_bytes']);
        $this->assertStringContainsString('not found', $summary['live_lookup_reason']);
    }

    public function test_custom_pipeline_failure_explains_the_need_for_captured_previews(): void
    {
        $store = new FileStore($this->cacheDir, new EnvelopeCodec(new JsonSerializer()));
        $store->set(Key::named('json-key'), ['name' => 'Cacheer'], Ttl::forever());

        $summary = $this->inspectKey('json-key');

        $this->assertNull($summary['last_value_preview']);
        $this->assertStringContainsString('Custom codecs', $summary['live_lookup_reason']);
        $this->assertStringNotContainsString('UnsupportedEnvelopeException', $summary['live_lookup_reason']);
    }

    public function test_process_local_store_explains_why_live_lookup_is_unavailable(): void
    {
        $summary = $this->inspectKey('array-key', ['driver' => 'ArrayStore']);

        $this->assertNull($summary['last_value_preview']);
        $this->assertStringContainsString('application process', $summary['live_lookup_reason']);
    }

    public function test_disabled_capture_does_not_read_live_values(): void
    {
        Cacheer::file($this->cacheDir)->set('private-key', 'private value');
        putenv('CACHEER_MONITOR_CAPTURE_VALUES=false');

        $summary = $this->inspectKey('private-key', ['live' => '1']);

        $this->assertFalse($summary['capture_values_enabled']);
        $this->assertNull($summary['last_value_preview']);
        $this->assertArrayNotHasKey('live_lookup_reason', $summary);
    }

    /**
     * @param array<string,string> $query
     * @return array<string,mixed>
     */
    private function snapshotWith(array $query): array
    {
        $response = ApiController::fromConfig()->snapshot(new Request('GET', '/api/snapshot', $query));

        return json_decode($response->body, true);
    }

    private function recordKey(string $key, array $metadata = []): void
    {
        file_put_contents($this->eventsFile, json_encode([
            'ts' => microtime(true),
            'type' => 'put',
            'payload' => array_merge(['key' => $key, 'driver' => 'FileStore'], $metadata),
        ]) . PHP_EOL);
    }

    private function inspectKey(string $key, array $query = []): array
    {
        $response = ApiController::fromConfig()->keyInspect(new Request('GET', '/api/keys/inspect', array_merge([
            'key' => $key, 'driver' => 'FileStore',
        ], $query)));
        $this->assertSame(200, $response->status);

        return json_decode($response->body, true)['summary'];
    }
}
