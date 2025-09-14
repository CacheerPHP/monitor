<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Http;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query
    ) {}

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $query = $_GET ?? [];
        return new self($method, $path, $query);
    }
}

