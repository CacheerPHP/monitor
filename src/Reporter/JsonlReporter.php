<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Reporter;

use Cacheer\Monitor\Support\Env;
use Cacheer\Monitor\Support\Path;

/**
 * Reporter that appends events to a JSONL file with file rotation.
 */
final class JsonlReporter implements MetricsReporterInterface
{
    private string $filePath;

    private ?int $maxBytes;

    private string $instanceId;

    /** @var resource|null Reused lock-file handle, opened lazily on first write. */
    private $lockHandle = null;

    /**
     * @param string|null $filePath   Explicit events file path (optional)
     * @param int|null    $maxBytes   Max file size before rotation (null to disable)
     * @param string|null $instanceId Optional instance id for tagging events
     */
    public function __construct(?string $filePath = null, ?int $maxBytes = 10485760, ?string $instanceId = null)
    {
        $resolved = null;
        if ($filePath && $filePath !== '') {
            $resolved = Path::resolve($filePath, Env::root());
        } else {
            $osEnv = getenv('CACHEER_MONITOR_EVENTS');
            if ($osEnv !== false && $osEnv !== null && $osEnv !== '') {
                $resolved = Path::resolve((string) $osEnv, Env::root());
            } else {
                $dotEnv = Env::get('CACHEER_MONITOR_EVENTS');
                if ($dotEnv) {
                    $resolved = Path::resolve((string) $dotEnv, Env::root());
                } else {
                    $resolved = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cacheer-monitor.jsonl';
                }
            }
        }
        $this->filePath = $resolved;
        $this->maxBytes = $maxBytes;
        $this->instanceId = $instanceId ?: bin2hex(random_bytes(4));
        $this->ensureDir();
    }

    /**
     * Append a single event to the JSONL file.
     *
     * Uses a lock file to serialize rotation checks and writes,
     * preventing race conditions between concurrent processes.
     *
     * @param string $type
     * @param array<string,mixed> $payload
     */
    public function event(string $type, array $payload = []): void
    {
        $record = [
            'ts' => microtime(true),
            'type' => $type,
            'instance' => $this->instanceId,
            'payload' => $payload,
        ];
        $line = json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL;
        $lineBytes = strlen($line);

        // Serialize rotation + write via a separate lock file. The handle is
        // opened once and reused for the reporter's lifetime, so a high-traffic
        // cache doesn't pay an fopen/fclose on every single operation.
        $lockHandle = $this->lockHandle();
        if ($lockHandle === null) {
            // Fallback: write without rotation protection.
            @file_put_contents($this->filePath, $line, FILE_APPEND | LOCK_EX);
            return;
        }

        flock($lockHandle, LOCK_EX);
        try {
            // Rotate while holding the lock — safe from race conditions.
            $this->rotateIfNeeded($lineBytes);
            @file_put_contents($this->filePath, $line, FILE_APPEND);
        } finally {
            flock($lockHandle, LOCK_UN);
        }
    }

    /**
     * Lazily open and reuse a single lock-file handle. The lock file is never
     * rotated/renamed, so the handle stays valid for the whole request.
     *
     * @return resource|null
     */
    private function lockHandle()
    {
        if (!is_resource($this->lockHandle)) {
            $handle = @fopen($this->filePath . '.lock', 'cb');
            $this->lockHandle = $handle === false ? null : $handle;
        }

        return $this->lockHandle;
    }

    public function __destruct()
    {
        if (is_resource($this->lockHandle)) {
            @fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    /** Ensure target directory exists. */
    private function ensureDir(): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    /**
     * Rotate the file if adding bytes would exceed max threshold.
     * Caller must hold the lock file before calling this.
     */
    private function rotateIfNeeded(int $incomingBytes): void
    {
        if ($this->maxBytes === null) {
            return;
        }

        clearstatcache(true, $this->filePath);
        $size = file_exists($this->filePath) ? (int) filesize($this->filePath) : 0;

        if (($size + $incomingBytes) > $this->maxBytes) {
            $date = date('Ymd_His');
            @rename($this->filePath, $this->filePath . '.' . $date . '.rotated');
        }
    }
}
