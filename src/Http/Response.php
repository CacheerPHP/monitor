<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Http;

/**
 * Minimal HTTP response wrapper.
 */
final class Response
{
    /**
     * @param int $status HTTP status code
     * @param array<string,string> $headers HTTP headers
     * @param string $body Response body
     */
    public function __construct(
        public readonly int $status = 200,
        public readonly array $headers = [],
        public readonly string $body = ''
    ) {}

    /**
     * Build a JSON response.
     *
     * @param array<string,mixed> $data
     * @param int $status
     * @return self
     */
    public static function json(array $data, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'application/json'], json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Send the response to the client.
     *
     * @return void
     */
    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        echo $this->body;
    }
}
