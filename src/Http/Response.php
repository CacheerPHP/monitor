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
        // JSON_PARTIAL_OUTPUT_ON_ERROR keeps malformed telemetry (e.g. invalid
        // UTF-8 in a captured value) from blanking the whole response; the
        // null-coalesce guards the rare case where encoding fails outright.
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($body === false) {
            // We're returning an error body, so the status must reflect an error:
            // promote any success/redirect (< 400) code to 500, but keep an
            // already-failing 4xx/5xx as-is.
            return new self(
                $status < 400 ? 500 : $status,
                ['Content-Type' => 'application/json'],
                '{"ok":false,"error":"Failed to encode response"}'
            );
        }

        return new self($status, ['Content-Type' => 'application/json'], $body);
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
