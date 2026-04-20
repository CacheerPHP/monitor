<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Http;

use Cacheer\Monitor\Application\EventExportService;
use Cacheer\Monitor\Application\KeyInspectionService;
use Cacheer\Monitor\Application\LiveCachePreviewer;
use Cacheer\Monitor\Application\MonitorContext;
use Cacheer\Monitor\Application\MonitorReadService;

final class ApiController
{
    public function __construct(
        private readonly MonitorContext $context,
        private readonly MonitorReadService $readService,
        private readonly KeyInspectionService $keyInspectionService,
        private readonly EventExportService $eventExportService,
        private readonly EventStreamResponder $eventStreamResponder,
    ) {
    }

    /**
     * @return self
     */
    public static function fromConfig(): self
    {
        $context = new MonitorContext();

        return new self(
            $context,
            new MonitorReadService($context),
            new KeyInspectionService($context, new LiveCachePreviewer()),
            new EventExportService($context),
            new EventStreamResponder($context),
        );
    }

    /**
     * Parse shared time-range query params (from / until) from a request.
     *
     * @return array{float|null, float|null}
     */
    private function parseTimeRange(Request $request): array
    {
        $from  = isset($request->query['from'])  ? (float) $request->query['from']  : null;
        $until = isset($request->query['until']) ? (float) $request->query['until'] : null;
        return [$from, $until];
    }

    /**
     * Returns the health status of the application.
     *
     * @return Response
     */
    public function health(): Response
    {
        return Response::json($this->readService->health());
    }

    /**
     * Returns the configuration of the application.
     *
     * @return Response
     */
    public function config(): Response
    {
        return Response::json($this->readService->configuration());
    }

    /**
     * Returns the metrics of the application.
     *
     * @param Request $request
     * @return Response
     */
    public function metrics(Request $request): Response
    {
        $namespaceFilter = isset($request->query['namespace']) ? (string) $request->query['namespace'] : null;
        $limitParam = isset($request->query['limit']) ? (int) $request->query['limit'] : 1000;
        [$from, $until] = $this->parseTimeRange($request);

        return Response::json(
            $this->readService->metrics($namespaceFilter, $limitParam, $from, $until)
        );
    }

    /**
     * Returns the events of the application.
     *
     * @param Request $request
     * @return Response
     */
    public function events(Request $request): Response
    {
        $limit = isset($request->query['limit']) ? (int) $request->query['limit'] : 200;
        $namespaceFilter = isset($request->query['namespace']) ? (string) $request->query['namespace'] : null;
        [$from, $until] = $this->parseTimeRange($request);

        return Response::json(
            $this->readService->events($limit, $namespaceFilter, $from, $until)
        );
    }

    /**
     * Inspects a specific key in the application.
     *
     * @param Request $request
     * @return Response
     */
    public function keyInspect(Request $request): Response
    {
        $key = isset($request->query['key']) ? (string) $request->query['key'] : '';

        if ($key === '') {
            return $this->errorResponse(400, 'key parameter is required');
        }

        $namespace = isset($request->query['namespace']) ? (string) $request->query['namespace'] : null;
        $limit = isset($request->query['limit']) ? (int) $request->query['limit'] : 100;
        $forceLive = filter_var($request->query['live'] ?? false, FILTER_VALIDATE_BOOL);

        return Response::json(
            $this->keyInspectionService->inspect($key, $namespace, $limit, $forceLive)
        );
    }

    /**
     * Exports the events of the application in the specified format.
     *
     * @param Request $request
     * @return Response
     */
    public function export(Request $request): Response
    {
        $format = (string) ($request->query['format'] ?? 'json');
        $limit = isset($request->query['limit']) ? (int) $request->query['limit'] : 0;
        $namespaceFilter = isset($request->query['namespace']) ? (string) $request->query['namespace'] : null;
        [$from, $until] = $this->parseTimeRange($request);

        $export = $this->eventExportService->export($format, $limit, $namespaceFilter, $from, $until);

        return new Response(200, [
            'Content-Type' => $export['content_type'],
            'Content-Disposition' => 'attachment; filename="' . $export['filename'] . '"',
        ], $export['body']);
    }

    /**
     * Cleans up rotated event files older than the specified max age.
     *
     * @param Request $request
     * @return Response
     */
    public function cleanupRotated(Request $request): Response
    {
        if ($request->method !== 'POST') {
            return $this->errorResponse(405, 'Method Not Allowed', ['Allow' => 'POST']);
        }

        $maxAgeDays = 7;
        $body = $this->jsonBody();

        if (is_array($body) && isset($body['max_age_days'])) {
            $maxAgeDays = max(1, (int) $body['max_age_days']);
        }

        return Response::json([
            'ok' => true,
            'deleted' => $this->context->store()->cleanRotated($maxAgeDays),
        ]);
    }

    /**
     * Clears all events from the store.
     *
     * @param Request $request
     * @return Response
     */
    public function clear(Request $request): Response
    {
        if ($request->method !== 'POST') {
            return $this->errorResponse(405, 'Method Not Allowed', ['Allow' => 'POST']);
        }

        $requiredToken = $this->context->requiredToken();
        if ($requiredToken !== null) {
            $provided = $_SERVER['HTTP_X_MONITOR_TOKEN'] ?? '';

            if (!\is_string($provided) || !hash_equals((string) $requiredToken, $provided)) {
                return $this->errorResponse(401, 'Unauthorized');
            }
        }

        return Response::json(['ok' => $this->context->store()->clear()]);
    }

    /**
     * Streams events to the client in real-time.
     *
     * @param Request $request
     * @return void
     */
    public function stream(Request $request): void
    {
        $this->eventStreamResponder->stream();
    }

    /**
     * Parses the JSON body of the request.
     * 
     * @return array<string,mixed>
     */
    private function jsonBody(): array
    {
        $body = @json_decode((string) file_get_contents('php://input'), true);

        return is_array($body) ? $body : [];
    }

    /**
     * Generates an error response with the given status code, message, and optional headers.
     * 
     * @param array<string,string> $headers
     */
    private function errorResponse(int $status, string $message, array $headers = []): Response
    {
        return new Response(
            $status,
            array_merge(['Content-Type' => 'application/json'], $headers),
            (string) json_encode(['ok' => false, 'error' => $message])
        );
    }
}
