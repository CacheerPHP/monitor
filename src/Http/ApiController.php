<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Http;

use Cacheer\Monitor\Domain\Aggregator;
use Cacheer\Monitor\Domain\EventStore;
use Cacheer\Monitor\Support\ConfigResolver;

/**
 * HTTP API controller for the monitor endpoints.
 */
final class ApiController
{
    public function __construct(private readonly EventStore $store) {}

    /**
     * Factory method using current configuration to resolve the events file.
     *
     * @return self
     */
    public static function fromConfig(): self
    {
        [$eventsFilePath] = ConfigResolver::eventsFileWithOrigin();
        return new self(new EventStore($eventsFilePath));
    }

    /**
     * Health check endpoint.
     *
     * @return Response
     */
    public function health(): Response
    {
        return Response::json(['ok' => true]);
    }

    /**
     * Configuration info endpoint (current events file + origin).
     *
     * @return Response
     */
    public function config(): Response
    {
        [$eventsFilePath, $origin] = ConfigResolver::eventsFileWithOrigin();
        return Response::json(['events_file' => $eventsFilePath, 'origin' => $origin]);
    }

    /**
     * Metrics summary endpoint with optional namespace filter.
     *
     * @param Request $request
     * @return Response
     */
    public function metrics(Request $request): Response
    {
        $namespaceFilter = isset($request->query['namespace']) ? (string) $request->query['namespace'] : null;
        $eventRecords = $this->store->readAll(0, $namespaceFilter);
        $stats = Aggregator::summarize($eventRecords);
        return Response::json($stats);
    }

    /**
     * Events listing endpoint with limit + optional namespace filter.
     *
     * @param Request $request
     * @return Response
     */
    public function events(Request $request): Response
    {
        $limit = isset($request->query['limit']) ? (int) $request->query['limit'] : 200;
        $namespaceFilter = isset($request->query['namespace']) ? (string) $request->query['namespace'] : null;
        $eventRecords = $this->store->readAll($limit > 0 ? $limit : 0, $namespaceFilter);
        if ($limit > 0 && count($eventRecords) > $limit) {
            $eventRecords = array_slice($eventRecords, -$limit);
        }
        return Response::json($eventRecords);
    }

    /**
     * Clear events file endpoint (rotates/truncates).
     *
     * @param Request $request
     * @return Response
     */
    public function clear(Request $request): Response
    {
        if ($request->method !== 'POST') {
            return new Response(405, ['Allow' => 'POST'], json_encode(['ok' => false, 'error' => 'Method Not Allowed']));
        }
        $cleared = $this->store->clear();
        return Response::json(['ok' => $cleared]);
    }
}
