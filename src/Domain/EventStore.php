<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Domain;

/**
 * Read and manage the JSONL events file used by the dashboard.
 */
final class EventStore
{
    public function __construct(private readonly string $filePath) {}

    /**
     * Read events from the JSONL file, with optional limit and namespace filter.
     *
     * @param int $limit Maximum number of lines to read from the end (0 = all)
     * @param string|null $namespace Namespace filter
     * @return array<int,array<string,mixed>> Parsed events
     */
    public function readAll(int $limit = 0, ?string $namespace = null): array
    {
        if (!is_file($this->filePath)) {
            return [];
        }
        $lines = @file($this->filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        if ($limit > 0) {
            $lines = array_slice($lines, -$limit);
        }
        $events = [];
        foreach ($lines as $rawLine) {
            $decoded = json_decode($rawLine, true);
            if (!is_array($decoded)) {
                continue;
            }
            if ($namespace !== null) {
                $recordNamespace = $decoded['payload']['namespace'] ?? '';
                // Normalize: '(default)' matches empty namespace in events
                $normalizedFilter = ($namespace === '(default)') ? '' : $namespace;
                if ($recordNamespace !== $normalizedFilter) {
                    continue;
                }
            }
            $events[] = $decoded;
        }
        return $events;
    }

    /**
     * Rotate and truncate the events file.
     *
     * @return bool True if cleared successfully
     */
    public function clear(): bool
    {
        if (!is_file($this->filePath)) {
            return @touch($this->filePath);
        }
        $rotated = @rename($this->filePath, $this->filePath . '.' . date('Ymd_His') . '.bak');
        if (!$rotated) {
            // Rotation failed — truncate in place
            return @file_put_contents($this->filePath, '') !== false;
        }
        // Create fresh empty file
        return @file_put_contents($this->filePath, '') !== false;
    }

    /**
     * Get the underlying events file path.
     *
     * @return string
     */
    public function path(): string
    {
        return $this->filePath;
    }
}
