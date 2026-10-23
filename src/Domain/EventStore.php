<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Domain;

use Cacheer\Monitor\Support\EventLogFiles;

/**
 * Read and manage the JSONL events file used by the dashboard.
 */
final class EventStore
{
    public function __construct(private readonly string $filePath) {}

    /**
     * Read events from the JSONL file with optional limit, namespace, and time-range filters.
     *
     * @param int         $limit     Maximum matching events to return (0 = all)
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
        // Unfiltered tails can use the fast path. With filters, apply the limit
        // after matching so busy namespaces cannot hide quieter ones.
        $hasFilters = $namespace !== null || $from !== null || $until !== null;
        $events = [];
        if ($limit > 0 && !$hasFilters) {
            foreach ($this->tailLines($limit) as $rawLine) {
                $decoded = json_decode($rawLine, true);
                if (is_array($decoded)) {
                    $events[] = $decoded;
                }
            }
        } else {
            foreach ($this->stream($namespace, $from, $until) as $event) {
                $events[] = $event;
            }
        }
        if ($limit <= 0) {
            return $events;
        }

        // Reporters write in batches, so processes interleave in the file by batch
        // rather than by time. Order by timestamp (stable for ties) before taking
        // the newest matches. The unfiltered fast path only reads the file's last
        // $limit lines, so near its edge it can miss an event that arrived in a
        // later batch with an earlier timestamp (at most one flush interval old).
        usort($events, static fn (array $a, array $b): int => self::timestamp($a) <=> self::timestamp($b));

        return $hasFilters ? array_slice($events, -$limit) : $events;
    }

    /**
     * Decoded events matching the namespace and time filters, in file order,
     * one at a time — for consumers that aggregate as they read instead of
     * holding the whole log in memory.
     *
     * @return \Generator<int,array<string,mixed>>
     */
    public function stream(?string $namespace = null, ?float $from = null, ?float $until = null): \Generator
    {
        if (!is_file($this->filePath)) {
            return;
        }
        $normalizedFilter = $namespace === '(default)' ? '' : $namespace;
        foreach ($this->eachLine() as $rawLine) {
            $decoded = json_decode($rawLine, true);
            if (!is_array($decoded)) {
                continue;
            }
            if ($namespace !== null) {
                if (!array_key_exists('namespace', $decoded['payload'] ?? [])) {
                    continue;
                }
                if (($decoded['payload']['namespace'] ?? '') !== $normalizedFilter) {
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
            yield $decoded;
        }
    }

    /**
     * Return all events associated with a specific cache key, newest first.
     *
     * @param string      $key
     * @param string|null $namespace
     * @param int         $limit
     * @return array<int,array<string,mixed>>
     */
    public function readByKey(string $key, ?string $namespace = null, int $limit = 50, ?string $driver = null, bool $namespaceMissing = false): array
    {
        if (!is_file($this->filePath)) {
            return [];
        }
        $matches = [];
        foreach ($this->eachLine() as $rawLine) {
            $decoded = json_decode($rawLine, true);
            if (!is_array($decoded)) {
                continue;
            }
            if (($decoded['payload']['key'] ?? '') !== $key) {
                continue;
            }
            if ($driver !== null && ($decoded['payload']['driver'] ?? 'unknown') !== $driver) {
                continue;
            }
            if ($namespaceMissing && array_key_exists('namespace', $decoded['payload'] ?? [])) {
                continue;
            }
            if ($namespace !== null) {
                if (!array_key_exists('namespace', $decoded['payload'] ?? [])) {
                    continue;
                }
                $recordNs = $decoded['payload']['namespace'] ?? '';
                $normalizedFilter = ($namespace === '(default)') ? '' : $namespace;
                if ($recordNs !== $normalizedFilter) {
                    continue;
                }
            }
            $matches[] = $decoded;
        }
        // Newest first, by timestamp: file position is only ordered within a process.
        $matches = array_reverse($matches);
        usort($matches, static fn (array $a, array $b): int => self::timestamp($b) <=> self::timestamp($a));
        if ($limit > 0 && count($matches) > $limit) {
            $matches = array_slice($matches, 0, $limit);
        }
        return $matches;
    }

    /**
     * @param array<string,mixed> $event
     */
    private static function timestamp(array $event): float
    {
        return isset($event['ts']) && is_numeric($event['ts']) ? (float) $event['ts'] : 0.0;
    }

    /**
     * Yield non-empty lines one at a time without holding the whole file in
     * memory. Used for unbounded scans (full metrics, per-key history, export).
     *
     * @return \Generator<int,string>
     */
    private function eachLine(): \Generator
    {
        $handle = @fopen($this->filePath, 'rb');
        if ($handle === false) {
            return;
        }
        try {
            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line !== '') {
                    yield $line;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Read the last $limit non-empty lines by scanning backwards in fixed-size
     * chunks, so a "last N events" query touches only the tail of the file
     * rather than reading and parsing all of it.
     *
     * Lines are returned in file order (oldest first), matching the previous
     * file(FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) + array_slice(-N).
     *
     * @return list<string>
     */
    private function tailLines(int $limit): array
    {
        $handle = @fopen($this->filePath, 'rb');
        if ($handle === false) {
            return [];
        }

        $buffer = '';
        try {
            if (fseek($handle, 0, SEEK_END) !== 0) {
                return [];
            }
            $pos = ftell($handle);
            if ($pos === false || $pos === 0) {
                return [];
            }

            $chunkSize    = 65536;
            $newlineCount = 0;

            // Read one extra newline beyond the limit so the oldest kept line
            // is guaranteed complete (not truncated mid-chunk).
            while ($pos > 0 && $newlineCount <= $limit) {
                $read   = (int) min($chunkSize, $pos);
                $pos   -= $read;
                fseek($handle, $pos, SEEK_SET);
                $chunk  = (string) fread($handle, $read);
                $buffer = $chunk . $buffer;
                $newlineCount += substr_count($chunk, "\n");
            }
        } finally {
            fclose($handle);
        }

        $lines = preg_split("/\r?\n/", $buffer, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($lines) > $limit) {
            $lines = array_slice($lines, -$limit);
        }
        return $lines;
    }

    /**
     * Delete this events file's archives older than $maxAgeDays days.
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
        $cutoff  = time() - (max(1, $maxAgeDays) * 86400);
        $deleted = 0;
        $files   = @scandir($dir) ?: [];
        foreach ($files as $file) {
            if (!EventLogFiles::isArchive($this->filePath, $file)) {
                continue;
            }
            $fullPath = $dir . DIRECTORY_SEPARATOR . $file;
            if (!is_file($fullPath) || is_link($fullPath)) {
                continue;
            }
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
        $lock = @fopen($this->filePath . '.lock', 'cb');
        if ($lock === false) {
            return false;
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                return false;
            }
            if (is_file($this->filePath) && !EventLogFiles::archive($this->filePath, 'bak')) {
                return false;
            }

            return @file_put_contents($this->filePath, '') !== false;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
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
