<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Http;

use Cacheer\Monitor\Domain\Aggregator;
use Cacheer\Monitor\Domain\EventStore;
use Cacheer\Monitor\Support\ConfigResolver;

final class ApiController
{
    public function __construct(private readonly EventStore $store) {}

    public static function fromConfig(): self
    {
        [$file] = ConfigResolver::eventsFileWithOrigin();
        return new self(new EventStore($file));
    }

    public function health(): Response
    {
        return Response::json(['ok' => true]);
    }

    public function config(): Response
    {
        [$file, $origin] = ConfigResolver::eventsFileWithOrigin();
        return Response::json(['events_file' => $file, 'origin' => $origin]);
    }

    public function metrics(Request $request): Response
    {
        $namespace = isset($request->query['namespace']) ? (string) $request->query['namespace'] : null;
        $events = $this->store->readAll(0, $namespace);
        $stats = Aggregator::summarize($events);
        return Response::json($stats);
    }

    public function events(Request $request): Response
    {
        $limit = isset($request->query['limit']) ? (int) $request->query['limit'] : 200;
        $namespace = isset($request->query['namespace']) ? (string) $request->query['namespace'] : null;
        $events = $this->store->readAll($limit > 0 ? $limit : 0, $namespace);
        if ($limit > 0 && count($events) > $limit) {
            $events = array_slice($events, -$limit);
        }
        return Response::json($events);
    }

    public function clear(Request $request): Response
    {
        if ($request->method !== 'POST') {
            return new Response(405, ['Allow' => 'POST'], json_encode(['ok' => false, 'error' => 'Method Not Allowed']));
        }
        $ok = $this->store->clear();
        return Response::json(['ok' => $ok]);
    }
}

