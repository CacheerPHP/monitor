<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Tests\Unit;

use Cacheer\Monitor\CacheerMonitorListener;
use Cacheer\Monitor\Reporter\MetricsReporterInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Observability\CacheEvent;
use Silviooosilva\CacheerPhp\Observability\CacheEventType;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Silviooosilva\CacheerPhp\Support\CircuitBreaker;

class CacheerMonitorListenerTest extends TestCase
{
    /** @var list<array{type: string, payload: array<string, mixed>}> */
    private array $records = [];

    private CacheerMonitorListener $listener;

    protected function setUp(): void
    {
        $this->records = [];
        $records = &$this->records;
        $this->listener = new CacheerMonitorListener(new class ($records) implements MetricsReporterInterface {
            /** @param list<array{type: string, payload: array<string, mixed>}> $records */
            public function __construct(private array &$records)
            {
            }

            public function event(string $type, array $payload = []): void
            {
                $this->records[] = ['type' => $type, 'payload' => $payload];
            }
        });
    }

    public function test_an_event_without_context_records_none(): void
    {
        // The shape CacheerPHP 6.0 sends: no scope, TTL, or operation.
        $this->listener->dispatch(CacheEvent::written('ArrayStore', 'k', 12.0, 5));

        $this->assertSame('put', $this->records[0]['type']);
        $this->assertArrayNotHasKey('namespace', $this->records[0]['payload']);
        $this->assertArrayNotHasKey('ttl', $this->records[0]['payload']);
        $this->assertArrayNotHasKey('operation', $this->records[0]['payload']);
        $this->assertSame(0.012, $this->records[0]['payload']['duration_ms']);
    }

    public function test_operations_are_recorded_with_their_namespace_and_ttl(): void
    {
        $this->requireTelemetryContext();
        $cache = Cacheer::instrumented(new ArrayStore(), $this->listener);
        $shop = $cache->in('shop');

        $shop->set('product', 'boots', 60);
        $shop->forever('banner', 'sale');
        $shop->touch('product', 120);
        $shop->increment('views');
        $shop->tag('product', 'catalog');
        $shop->flushTag('catalog');
        $cache->get('root-key');

        $this->assertSame(
            [
                ['put', 'shop', 60, 'set'],
                ['put', 'shop', null, 'set'],
                ['renew', 'shop', 120, 'touch'],
                ['increment', 'shop', 'absent', 'increment'],
                ['tag', 'shop', 'absent', 'tag'],
                ['flush_tag', 'absent', 'absent', 'clearTag'],
                ['miss', '', 'absent', 'get'],
            ],
            array_map(static fn (array $record): array => [
                $record['type'],
                array_key_exists('namespace', $record['payload']) ? $record['payload']['namespace'] : 'absent',
                array_key_exists('ttl', $record['payload']) ? $record['payload']['ttl'] : 'absent',
                $record['payload']['operation'],
            ], $this->records),
        );
    }

    public function test_a_loader_run_is_recorded_with_its_duration(): void
    {
        $this->requireTelemetryContext();
        $cache = Cacheer::instrumented(new ArrayStore(), $this->listener);

        $cache->remember('report', 60, static function (): string {
            usleep(2000);

            return 'done';
        });

        $computed = array_values(array_filter($this->records, static fn (array $record): bool => $record['type'] === 'computed'));
        $this->assertCount(1, $computed);
        $this->assertSame('report', $computed[0]['payload']['key']);
        $this->assertSame('ArrayStore', $computed[0]['payload']['driver']);
        $this->assertGreaterThanOrEqual(2.0, $computed[0]['payload']['duration_ms']);
    }

    public function test_an_outage_is_recorded_with_its_cause_redacted(): void
    {
        $this->requireTelemetryContext();
        $primary = new class () implements Store {
            public function get(Key $key): CacheEntry
            {
                throw new RuntimeException('connect failed: redis://cache:secret@10.0.0.5:6379');
            }

            public function set(Key $key, mixed $value, Ttl $ttl): void
            {
            }

            public function delete(Key $key): bool
            {
                return false;
            }

            public function clear(): void
            {
            }
        };
        $cache = Cacheer::resilient($primary, new ArrayStore(), new CircuitBreaker(new \Silviooosilva\CacheerPhp\Support\SystemClock(), failureThreshold: 1), events: $this->listener);

        $cache->get('k');

        $types = array_column($this->records, 'type');
        $this->assertSame(['circuit_opened', 'fallback_served'], array_values(array_intersect($types, ['circuit_opened', 'fallback_served'])));
        $opened = $this->records[array_search('circuit_opened', $types, true)]['payload'];
        $this->assertSame('ResilientStore', $opened['driver']);
        $this->assertStringContainsString('redis://***@10.0.0.5', $opened['error']);
        $this->assertStringNotContainsString('secret', $opened['error']);
    }

    private function requireTelemetryContext(): void
    {
        if (!defined(CacheEventType::class . '::Computed')) {
            $this->markTestSkipped('Needs CacheerPHP 6.1 telemetry context.');
        }
    }
}
