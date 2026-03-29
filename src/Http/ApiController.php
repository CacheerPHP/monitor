<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Http;

use Cacheer\Monitor\Support\Env;
use Cacheer\Monitor\Domain\Aggregator;
use Cacheer\Monitor\Domain\EventStore;
use Cacheer\Monitor\Support\ConfigResolver;

/**
 * HTTP API controller for the monitor endpoints.
 */
final class ApiController
{
    public function __construct() {}

    /**
     * Factory method using current configuration to resolve the events file.
     *
     * @return self
     */
    public static function fromConfig(): self { return new self(); }

    /** Resolve an EventStore for current config (re-evaluates each call). */
    private function resolveStore(): EventStore
    {
        [$eventsFilePath] = ConfigResolver::eventsFileWithOrigin();
        return new EventStore($eventsFilePath);
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
        $limitParam = isset($request->query['limit']) ? (int) $request->query['limit'] : 1000; // default: last 1000 events
        if ($limitParam < 0) { $limitParam = 0; }
        $eventRecords = $this->resolveStore()->readAll($limitParam, $namespaceFilter);
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
        $eventRecords = $this->resolveStore()->readAll($limit > 0 ? $limit : 0, $namespaceFilter);
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
        // Optional token protection via CACHEER_MONITOR_TOKEN (header only)
        $requiredToken = Env::get('CACHEER_MONITOR_TOKEN');
        if ($requiredToken) {
            $provided = $_SERVER['HTTP_X_MONITOR_TOKEN'] ?? '';
            if (!\is_string($provided) || !hash_equals((string) $requiredToken, $provided)) {
                return new Response(401, ['Content-Type' => 'application/json'], json_encode(['ok' => false, 'error' => 'Unauthorized']));
            }
        }
        $cleared = $this->resolveStore()->clear();
        return Response::json(['ok' => $cleared]);
    }

    /**
     * Server-Sent Events stream that emits new event lines as they are appended.
     * Runs for ~30 seconds and then closes (client should reconnect).
     *
     * @param Request $request
     * @return void (outputs directly)
     */
    public function stream(Request $request): void
    {
        $timeout = (int) (Env::get('CACHEER_MONITOR_STREAM_TIMEOUT', 30));
        $maxChunkBytes = 1024 * 1024; // 1MB max per read

        @ignore_user_abort(true);
        @set_time_limit(0);
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');

        $file = $this->resolveStore()->path();
        $lastSize = is_file($file) ? (int) filesize($file) : 0;
        $start = time();
        while (!connection_aborted() && (time() - $start) < $timeout) {
            clearstatcache(true, $file);
            $currentSize = is_file($file) ? (int) filesize($file) : 0;
            if ($currentSize > $lastSize) {
                $fh = @fopen($file, 'rb');
                if ($fh) {
                    @fseek($fh, $lastSize);
                    $chunk = stream_get_contents($fh, $maxChunkBytes) ?: '';
                    @fclose($fh);
                    $lines = preg_split("/[\r\n]+/", $chunk, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    foreach ($lines as $line) {
                        echo 'data: ' . $line . "\n\n";
                    }
                    @ob_flush();
                    @flush();
                }
                $lastSize = $currentSize;
            } else {
                // heartbeat
                echo "event: ping\n";
                echo 'data: ' . json_encode(['ts' => microtime(true)]) . "\n\n";
                @ob_flush();
                @flush();
            }
            usleep(500000); // 0.5s
        }
    }
}
