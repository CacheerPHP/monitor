<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Http;

final class Response
{
    public function __construct(
        public readonly int $status = 200,
        public readonly array $headers = [],
        public readonly string $body = ''
    ) {}

    public static function json(array $data, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'application/json'], json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        echo $this->body;
    }
}

