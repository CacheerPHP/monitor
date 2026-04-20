<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Application;

/**
 * Builds export payloads for event history in JSON or CSV form.
 */
final class EventExportService
{
    public function __construct(private readonly MonitorContext $context)
    {
    }

    /**
     * @return array{content_type:string,filename:string,body:string}
     */
    public function export(string $format = 'json', int $limit = 0, ?string $namespace = null, ?float $from = null, ?float $until = null): array
    {
        $events = $this->context->store()->readAll(max(0, $limit), $namespace, $from, $until);

        if (strtolower($format) === 'csv') {
            return [
                'content_type' => 'text/csv; charset=utf-8',
                'filename' => 'cacheer-events.csv',
                'body' => $this->buildCsv($events),
            ];
        }

        return [
            'content_type' => 'application/json',
            'filename' => 'cacheer-events.json',
            'body' => (string) json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $events
     */
    private function buildCsv(array $events): string
    {
        $csv = "ts,type,key,namespace,driver,duration_ms,success,size_bytes,ttl\n";

        foreach ($events as $event) {
            $payload = $event['payload'] ?? [];
            $csv .= implode(',', array_map(
                static fn(mixed $value): string => '"' . str_replace('"', '""', (string) ($value ?? '')) . '"',
                [
                    $event['ts'] ?? '',
                    $event['type'] ?? '',
                    $payload['key'] ?? '',
                    $payload['namespace'] ?? '',
                    $payload['driver'] ?? '',
                    $payload['duration_ms'] ?? '',
                    isset($payload['success']) ? ($payload['success'] ? 'true' : 'false') : '',
                    $payload['size_bytes'] ?? '',
                    $payload['ttl'] ?? '',
                ]
            )) . "\n";
        }

        return $csv;
    }
}
