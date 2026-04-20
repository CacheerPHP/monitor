<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Application;

use Cacheer\Monitor\Domain\Aggregator;

/**
 * Builds the key-inspector payload from stored events and optional live cache state.
 */
final class KeyInspectionService
{
    public function __construct(
        private readonly MonitorContext $context,
        private readonly LiveCachePreviewer $liveCachePreviewer,
    ) {
    }

    /**
     * @return array{summary:array<string,mixed>,events:array<int,array<string,mixed>>}
     */
    public function inspect(string $key, ?string $namespace = null, int $limit = 100, bool $forceLive = false): array
    {
        $store = $this->context->store();
        $allKeyEvents = $store->readByKey($key, $namespace, 0);
        $summary = Aggregator::summarizeKey($key, $allKeyEvents);
        $summary['capture_values_enabled'] = $this->context->captureValuesEnabled();

        if ($summary['capture_values_enabled'] && ($forceLive || $summary['last_value_preview'] === null)) {
            $preview = $this->liveCachePreviewer->preview($key, $namespace, $this->driverHint($allKeyEvents));

            if ($preview !== null) {
                $summary = array_replace($summary, $preview);
            }
        }

        return [
            'summary' => $summary,
            'events' => $limit > 0 ? array_slice($allKeyEvents, 0, $limit) : $allKeyEvents,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $events
     */
    private function driverHint(array $events): ?string
    {
        $driverHint = $events[0]['payload']['driver'] ?? null;

        return is_string($driverHint) ? $driverHint : null;
    }
}
