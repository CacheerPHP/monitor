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
     * Read events from the JSONL file with optional limit, namespace, and time-range filters.
     *
     * @param int         $limit     Maximum number of lines to read from the end (0 = all)
     * @param string|null $namespace Namespace filter
     * @param float|null  $from      Unix timestamp — only events at or after this time
     * @param float|null  $until     Unix timestamp — only events at or before this time
     * @return array<int,array<string,mixed>>
     */
    public function readAll(int $limit = 0, ?string $namespace = null, ?float $from = null, ?float $until = null): array
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
                $normalizedFilter = ($namespace === '(default)') ? '' : $namespace;
                if ($recordNamespace !== $normalizedFilter) {
                    continue;
                }
            }
            $ts = isset($decoded['ts']) ? (float) $decoded['ts'] : null;
            if ($from !== null && ($ts === null || $ts < $from)) {
                continue;
            }
            if ($until !== null && ($ts === null || $ts > $until)) {
                continue;
            }
            $events[] = $decoded;
        }
        return $events;
    }

    /**
     * Return all events associated with a specific cache key, newest first.
     *
     * @param string      $key
     * @param string|null $namespace
     * @param int         $limit
     * @return array<int,array<string,mixed>>
     */
    public function readByKey(string $key, ?string $namespace = null, int $limit = 50): array
    {
        if (!is_file($this->filePath)) {
            return [];
        }
        $lines = @file($this->filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $matches = [];
        foreach ($lines as $rawLine) {
            $decoded = json_decode($rawLine, true);
            if (!is_array($decoded)) {
                continue;
            }
            if (($decoded['payload']['key'] ?? '') !== $key) {
                continue;
            }
            if ($namespace !== null) {
                $recordNs = $decoded['payload']['namespace'] ?? '';
                $normalizedFilter = ($namespace === '(default)') ? '' : $namespace;
                if ($recordNs !== $normalizedFilter) {
                    continue;
                }
            }
            $matches[] = $decoded;
        }
        // Newest first
        $matches = array_reverse($matches);
        if ($limit > 0 && count($matches) > $limit) {
            $matches = array_slice($matches, 0, $limit);
        }
        return $matches;
    }

    /**
     * Delete rotated event files (.rotated / .bak) older than $maxAgeDays days.
     *
     * @param int $maxAgeDays
     * @return int Number of files deleted
     */
    public function cleanRotated(int $maxAgeDays = 7): int
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            return 0;
        }
        $cutoff  = time() - ($maxAgeDays * 86400);
        $deleted = 0;
        $files   = @scandir($dir) ?: [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            if (!str_ends_with($file, '.rotated') && !str_ends_with($file, '.bak')) {
                continue;
            }
            $fullPath = $dir . DIRECTORY_SEPARATOR . $file;
            $mtime    = @filemtime($fullPath);
            if ($mtime !== false && $mtime < $cutoff) {
                if (@unlink($fullPath)) {
                    $deleted++;
                }
            }
        }
        return $deleted;
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
            return @file_put_contents($this->filePath, '') !== false;
        }
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
