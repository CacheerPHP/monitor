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
        // For a bounded request, scan backwards and read only the tail bytes we
        // need instead of slurping (and JSON-decoding) the entire file. The
        // limit applies to raw lines first, then namespace/time filters narrow
        // the result — same semantics as the previous file()+array_slice path.
        $lines = $limit > 0 ? $this->tailLines($limit) : $this->eachLine();

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
        $matches = [];
        foreach ($this->eachLine() as $rawLine) {
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
        // Best-effort: rotate the current log aside, then truncate. Whether or
        // not the rename succeeds, the live file ends up empty (recreated).
        @rename($this->filePath, $this->filePath . '.' . date('Ymd_His') . '.bak');
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
