<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Reporter;

/**
 * Abstraction for emitting telemetry events.
 */
interface MetricsReporterInterface
{
    /**
     * Emit a telemetry event.
     * Examples of $type: hit, miss, put, clear, flush, renew, tag, error.
     *
     * @param string $type
     * @param array<string,mixed> $payload
     * @return void
     */
    public function event(string $type, array $payload = []): void;
}
