<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Domain;

final class EventStore
{
    public function __construct(private readonly string $filePath) {}

    public function readAll(int $limit = 0, ?string $namespace = null): array
    {
        if (!is_file($this->filePath)) return [];
        $lines = @file($this->filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        if ($limit > 0) {
            $lines = array_slice($lines, -$limit);
        }
        $events = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) continue;
            if ($namespace !== null && $namespace !== '') {
                $ns = $decoded['payload']['namespace'] ?? '';
                if ($ns !== $namespace) continue;
            }
            $events[] = $decoded;
        }
        return $events;
    }

    public function clear(): bool
    {
        if (!is_file($this->filePath)) {
            return (bool) @touch($this->filePath);
        }
        $ok = @rename($this->filePath, $this->filePath . '.' . date('Ymd_His') . '.bak');
        if (!$ok) {
            return (bool) @file_put_contents($this->filePath, '');
        }
        return (bool) @file_put_contents($this->filePath, '');
    }

    public function path(): string
    {
        return $this->filePath;
    }
}

