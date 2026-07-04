<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Application;

use Cacheer\Monitor\Domain\Aggregator;

/**
 * Read-only use cases for monitor health, config, metrics, and event feeds.
 */
final class MonitorReadService
{
    public function __construct(private readonly MonitorContext $context)
    {
    }

    /**
     * @return array{ok:true}
     */
    public function health(): array
    {
        return ['ok' => true];
    }

    /**
     * @return array{events_file:string,origin:string}
     */
    public function configuration(): array
    {
        return $this->context->configuration();
    }

    /**
     * @return array<string,mixed>
     */
    public function metrics(?string $namespace = null, int $limit = 1000, ?float $from = null, ?float $until = null): array
    {
        $events = $this->context->store()->readAll(max(0, $limit), $namespace, $from, $until);

        return Aggregator::summarize($events);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function events(int $limit = 200, ?string $namespace = null, ?float $from = null, ?float $until = null): array
    {
        return $this->context->store()->readAll(max(0, $limit), $namespace, $from, $until);
    }

    /**
     * Combined feed: read the events file once and return both the raw event
     * list and the aggregated metrics computed over the same set. Lets the
     * dashboard refresh everything with a single request/file read instead of
     * hitting /api/metrics and /api/events separately.
     *
     * @return array{metrics:array<string,mixed>,events:array<int,array<string,mixed>>}
     */
    public function snapshot(int $limit = 1000, ?string $namespace = null, ?float $from = null, ?float $until = null): array
    {
        $events = $this->context->store()->readAll(max(0, $limit), $namespace, $from, $until);

        return [
            'metrics' => Aggregator::summarize($events),
            'events'  => $events,
        ];
    }
}
