<?php

declare(strict_types=1);

namespace Cacheer\Monitor;

use Cacheer\Monitor\Reporter\MetricsReporterInterface;
use Silviooosilva\CacheerPhp\Contracts\CacheEventListener;

/**
 * Bridges CacheerPHP's built-in telemetry hook to the monitor reporter.
 * 
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Cacheer\Monitor
 */
final class CacheerMonitorListener implements CacheEventListener
{
    public function __construct(private MetricsReporterInterface $reporter) {}

    /**
     * Handles cache events emitted by CacheerPHP and forwards them to the reporter.
     *
     * @param string $event
     * @param string $key
     * @param array $context
     */
    public function on(string $event, string $key, array $context = []): void
    {
        $payload = array_filter([
            'key'         => $key !== '' ? $key : null,
            'namespace'   => ($context['namespace'] ?? '') !== '' ? $context['namespace'] : null,
            'driver'      => ($context['driver'] ?? '') !== '' ? $context['driver'] : null,
            'duration_ms' => $context['duration_ms'] ?? null,
            'success'     => $context['success'] ?? null,
        ], fn($v) => $v !== null);

        $this->reporter->event($event, $payload);
    }
}
