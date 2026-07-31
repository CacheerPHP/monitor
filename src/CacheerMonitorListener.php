<?php

declare(strict_types=1);

namespace Cacheer\Monitor;

use Cacheer\Monitor\Reporter\MetricsReporterInterface;
use Cacheer\Monitor\Support\ValueTelemetry;
use Silviooosilva\CacheerPhp\Contracts\EventDispatcher;
use Silviooosilva\CacheerPhp\Observability\CacheEvent;
use Silviooosilva\CacheerPhp\Observability\CacheEventType;

/**
 * Bridges CacheerPHP v6's typed telemetry events to the monitor reporter.
 *
 * v6 dispatches immutable {@see CacheEvent} objects through the {@see EventDispatcher}
 * contract (replacing v5's string-based CacheEventListener::on()). This listener
 * translates each event into the flat record shape the dashboard aggregates.
 *
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Cacheer\Monitor
 */
final class CacheerMonitorListener implements EventDispatcher
{
    private ValueTelemetry $valueTelemetry;

    public function __construct(private MetricsReporterInterface $reporter)
    {
        $this->valueTelemetry = new ValueTelemetry();
    }

    /**
     * Receive a cache event and forward it to the reporter.
     */
    public function dispatch(CacheEvent $event): void
    {
        $payload = [
            'key'         => $event->key !== null && $event->key !== '' ? $event->key : null,
            'driver'      => $event->store !== '' ? $event->store : null,
            'duration_ms' => round($event->durationMicros / 1000, 4),
            'success'     => $event->error === null,
            'size_bytes'  => $event->bytes,
            'count'       => $event->count,
        ];

        if ($event->error !== null) {
            $payload['error'] = $event->error->getMessage();
        }

        if ($event->hasValue) {
            $payload = array_merge($payload, $this->valueTelemetry->describe($event->value));
        }

        $this->reporter->event(
            $this->typeName($event->type),
            array_filter($payload, static fn ($value) => $value !== null),
        );
    }

    /**
     * Map a v6 event type to the short string the dashboard aggregates by. The
     * core hit/miss/put/clear/flush/error names match v5 so existing dashboards
     * keep working; v6-specific kinds pass through with descriptive names.
     */
    private function typeName(CacheEventType $type): string
    {
        return match ($type) {
            CacheEventType::Hit           => 'hit',
            CacheEventType::Miss          => 'miss',
            CacheEventType::Write         => 'put',
            CacheEventType::Delete        => 'clear',
            CacheEventType::Clear         => 'flush',
            CacheEventType::Prune         => 'prune',
            CacheEventType::Failure       => 'error',
            CacheEventType::Promotion     => 'promotion',
            CacheEventType::StaleServed   => 'stale_served',
            CacheEventType::Refresh       => 'refresh',
            CacheEventType::LockContended => 'lock_contended',
        };
    }
}
