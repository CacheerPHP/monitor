<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Reporter;

use Cacheer\Monitor\Support\Env;
use Cacheer\Monitor\Support\Path;

final class JsonlReporter implements MetricsReporterInterface
{
    /** @var string */
    private string $filePath;

    /** @var int|null */
    private ?int $maxBytes;

    /** @var string */
    private string $instanceId;

    /** Constructor
     *
     * @param string|null $filePath Path to the JSONL file. If null, uses CACHEER_MONITOR_EVENTS env or temp dir.
     * @param int|null $maxBytes Maximum file size in bytes before rotation. Null to disable rotation.
     * @param string|null $instanceId Unique instance ID for this reporter. If null, a random ID is generated.
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
        $this->maxBytes = $maxBytes; // 10MB default rotation
        $this->instanceId = $instanceId ?: bin2hex(random_bytes(4));
        $this->ensureDir();
    }

    /** Record an event to the JSONL file.
     *
     * Each event is a JSON object with:
     * - ts: timestamp in microseconds
     * - type: event type string
     * - instance: unique instance ID
     * - payload: associative array with event-specific data
     *
     * @param string $type Event type identifier
     * @param array $payload Additional event data
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

        $this->rotateIfNeeded(strlen($line));
        // Use file locking to avoid line interleaving
        $fh = fopen($this->filePath, 'ab');
        if ($fh) {
            @flock($fh, LOCK_EX);
            fwrite($fh, $line);
            @flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * Ensure the directory for the JSONL file exists.
     * @return void
     */
    private function ensureDir(): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    /**
     * Rotate the JSONL file if it exceeds the max size.
     * 
     * @param int $incomingBytes Size of the incoming write
     * @return void
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
