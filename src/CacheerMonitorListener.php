<?php

declare(strict_types=1);

namespace Cacheer\Monitor;

use Cacheer\Monitor\Reporter\MetricsReporterInterface;
use Cacheer\Monitor\Support\ValueTelemetry;
use Silviooosilva\CacheerPhp\Contracts\CacheEventListener;

/**
 * Bridges CacheerPHP's built-in telemetry hook to the monitor reporter.
 *
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Cacheer\Monitor
 */
final class CacheerMonitorListener implements CacheEventListener
{
    private ValueTelemetry $valueTelemetry;

    public function __construct(private MetricsReporterInterface $reporter)
    {
        $this->valueTelemetry = new ValueTelemetry();
    }

    /**
     * Handles cache events emitted by CacheerPHP and forwards them to the reporter.
     *
     * @param string $event
     * @param string $key
     * @param array  $context
     */
    public function on(string $event, string $key, array $context = []): void
    {
        $payload = [
            'key'         => $key !== '' ? $key : null,
            'namespace'   => ($context['namespace'] ?? '') !== '' ? $context['namespace'] : null,
            'driver'      => ($context['driver'] ?? '') !== '' ? $context['driver'] : null,
            'duration_ms' => $context['duration_ms'] ?? null,
            'success'     => $context['success'] ?? null,
            'ttl'         => $context['ttl'] ?? null,
        ];

        if (array_key_exists('value', $context)) {
            $payload = array_merge($payload, $this->valueTelemetry->describe($context['value']));
        }

        $this->reporter->event($event, array_filter($payload, fn($v) => $v !== null));
    }
}
