<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Http;

/**
 * Simple immutable HTTP request object.
 */
final class Request
{
    /**
     * @param string $method HTTP method (GET, POST, ...)
     * @param string $path   Request path (e.g., /api/metrics)
     * @param array<string,mixed> $query Query string parameters
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query
    ) {}

    /**
     * Build a Request from PHP globals.
     *
     * @return self
     */
    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $query = $_GET ?? [];
        return new self($method, $path, $query);
    }
}
