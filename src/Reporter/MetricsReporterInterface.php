<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Reporter;

interface MetricsReporterInterface
{
    /**
     * Generic event channel. Type examples: hit, miss, put, clear, flush, renew, tag, error
     * Payload is free-form but should be JSON-serializable.
     */
    public function event(string $type, array $payload = []): void;
}

