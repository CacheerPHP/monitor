<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Application;

use Cacheer\Monitor\Domain\Aggregator;
use Cacheer\Monitor\Domain\Timeline;

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
     * @return array<string,mixed>
     */
    public function snapshot(
        int $limit = 1000,
        ?string $namespace = null,
        ?float $from = null,
        ?float $until = null,
        string $keyFilter = '',
        string $eventType = '',
    ): array {
        // One pass over the log: metrics, timeline, and feed are all built as the
        // events stream past, so the decoded log never has to fit in memory.
        $end = $until ?? microtime(true);
        $timeline = new Timeline($from ?? $end - 600, $end);
        $feed = new FeedWindow($limit, $keyFilter, $eventType);
        $matching = 0;
        $tap = (static function (iterable $events) use ($timeline, $feed, &$matching): \Generator {
            foreach ($events as $event) {
                $matching++;
                $timeline->add($event);
                $feed->offer($event);
                yield $event;
            }
        })($this->context->store()->stream($namespace, $from, $until));

        $metrics = Aggregator::summarize($tap, $keyFilter);
        $visibleEvents = $feed->events();

        return [
            'metrics' => $metrics,
            'events'  => $visibleEvents,
            'coverage' => [
                'source' => 'current_log',
                'matching_events' => $matching,
                'matching_feed_events' => $feed->matching(),
                'shown_events' => count($visibleEvents),
            ],
            'timeline' => $timeline->toArray(),
        ];
    }
}
