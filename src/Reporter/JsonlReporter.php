<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Reporter;

use Cacheer\Monitor\Support\Env;
use Cacheer\Monitor\Support\EventLogFiles;
use Cacheer\Monitor\Support\Path;

/**
 * Reporter that appends events to a JSONL file with file rotation.
 *
 * Events are buffered in memory and appended in batches, because this runs on
 * every cache operation: a locked append per event cost ~30 µs, and workers
 * sharing the file queued on its lock. A batch is written when it reaches
 * $maxBufferedEvents or BUFFER_BYTES, when $maxBufferedSeconds have passed since
 * the last write (checked on the next event), on flush(), at shutdown — which
 * PHP also runs after a fatal error — and when the reporter is destroyed.
 *
 * Within a process, lines keep their order. Across processes they interleave by
 * batch, so readers order events by their timestamp, not their file position.
 */
final class JsonlReporter implements MetricsReporterInterface
{
    /** Flush before the buffer grows past this, whatever the event count. */
    private const BUFFER_BYTES = 262144;

    private string $filePath;

    private ?int $maxBytes;

    private string $instanceId;

    /** @var resource|null Reused lock-file handle, opened lazily on first write. */
    private $lockHandle = null;

    /** @var list<string> Encoded lines not yet written. */
    private array $buffer = [];

    private int $bufferedBytes = 0;

    private float $lastFlushAt;

    private bool $shutdownRegistered = false;

    /**
     * @param string|null $filePath           Explicit events file path (optional)
     * @param int|null    $maxBytes           Max file size before rotation (null to disable)
     * @param string|null $instanceId         Optional instance id for tagging events
     * @param int         $maxBufferedEvents  Write once this many events are buffered (1 writes every event)
     * @param float       $maxBufferedSeconds Write on the next event once this long has passed since the last write
     */
    public function __construct(
        ?string $filePath = null,
        ?int $maxBytes = 10485760,
        ?string $instanceId = null,
        private int $maxBufferedEvents = 256,
        private float $maxBufferedSeconds = 1.0,
    ) {
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
        $this->maxBufferedEvents = max(1, $this->maxBufferedEvents);
        $this->lastFlushAt = microtime(true);
        $this->ensureDir();
    }

    /**
     * The resolved events file this reporter writes to.
     *
     * Worth surfacing: with no CACHEER_MONITOR_EVENTS set, the default lands in
     * the system temp directory, so "the monitor reports nothing" is often
     * really "the dashboard is reading a different file".
     */
    public function filePath(): string
    {
        return $this->filePath;
    }

    /**
     * Buffer one event; write the buffer when it is full or old enough.
     *
     * @param string $type
     * @param array<string,mixed> $payload
     */
    public function event(string $type, array $payload = []): void
    {
        $now = microtime(true);
        $line = json_encode([
            'ts' => $now,
            'type' => $type,
            'instance' => $this->instanceId,
            'payload' => $payload,
        ], JSON_UNESCAPED_SLASHES);

        if ($line === false) {
            return;
        }

        $line .= PHP_EOL;
        $this->buffer[] = $line;
        $this->bufferedBytes += strlen($line);

        if (!$this->shutdownRegistered) {
            $this->registerShutdownFlush();
        }

        if (
            count($this->buffer) >= $this->maxBufferedEvents
            || $this->bufferedBytes >= self::BUFFER_BYTES
            || $now - $this->lastFlushAt >= $this->maxBufferedSeconds
        ) {
            $this->flush();
        }
    }

    /**
     * Write every buffered event, under the events file's lock.
     *
     * Rotation is checked between lines, so a batch splits across files at the
     * same points that writing one event at a time would.
     */
    public function flush(): void
    {
        $this->lastFlushAt = microtime(true);

        if ($this->buffer === []) {
            return;
        }

        $lines = $this->buffer;
        $this->buffer = [];
        $this->bufferedBytes = 0;

        // Serialize rotation + write via a separate lock file. The handle is
        // opened once and reused for the reporter's lifetime.
        $lockHandle = $this->lockHandle();
        if ($lockHandle === null || !flock($lockHandle, LOCK_EX)) {
            // Fallback: write without rotation protection.
            @file_put_contents($this->filePath, implode('', $lines), FILE_APPEND | LOCK_EX);
            return;
        }

        try {
            $this->writeLines($lines);
        } finally {
            flock($lockHandle, LOCK_UN);
        }
    }

    /**
     * Caller must hold the lock file.
     *
     * @param list<string> $lines
     */
    private function writeLines(array $lines): void
    {
        $size = $this->currentSize();
        $chunk = '';

        foreach ($lines as $line) {
            $lineBytes = strlen($line);

            if ($this->maxBytes !== null && $size > 0 && $size + $lineBytes > $this->maxBytes) {
                if ($chunk !== '') {
                    @file_put_contents($this->filePath, $chunk, FILE_APPEND);
                    $chunk = '';
                }
                EventLogFiles::archive($this->filePath, 'rotated');
                $size = $this->currentSize();
            }

            $chunk .= $line;
            $size += $lineBytes;
        }

        if ($chunk !== '') {
            @file_put_contents($this->filePath, $chunk, FILE_APPEND);
        }
    }

    /**
     * Flush at shutdown without keeping the reporter alive until then: a
     * reporter that goes out of scope earlier flushes from its destructor.
     */
    private function registerShutdownFlush(): void
    {
        $this->shutdownRegistered = true;
        $reporter = \WeakReference::create($this);

        register_shutdown_function(static function () use ($reporter): void {
            $reporter->get()?->flush();
        });
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
        $this->flush();

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

    private function currentSize(): int
    {
        if ($this->maxBytes === null) {
            return 0;
        }

        clearstatcache(true, $this->filePath);

        return file_exists($this->filePath) ? (int) filesize($this->filePath) : 0;
    }
}
