<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Application;

use Cacheer\Monitor\Domain\EventStore;
use Cacheer\Monitor\Support\ConfigResolver;
use Cacheer\Monitor\Support\Env;

/**
 * Centralizes runtime configuration and event-store resolution.
 */
final class MonitorContext
{
    /**
     * @return array{events_file:string,origin:string}
     */
    public function configuration(): array
    {
        [$eventsFilePath, $origin] = ConfigResolver::eventsFileWithOrigin();

        return [
            'events_file' => $eventsFilePath,
            'origin' => $origin,
        ];
    }

    public function store(): EventStore
    {
        return new EventStore($this->eventsFile());
    }

    public function eventsFile(): string
    {
        [$eventsFilePath] = ConfigResolver::eventsFileWithOrigin();

        return $eventsFilePath;
    }

    public function captureValuesEnabled(): bool
    {
        Env::reload();

        return Env::getBool('CACHEER_MONITOR_CAPTURE_VALUES');
    }

    public function requiredToken(): ?string
    {
        $token = Env::get('CACHEER_MONITOR_TOKEN');

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function streamTimeout(): int
    {
        return (int) Env::get('CACHEER_MONITOR_STREAM_TIMEOUT', 30);
    }
}
