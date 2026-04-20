<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Support;

/**
 * Centralizes value metadata and preview capture for cache telemetry payloads.
 */
final class ValueTelemetry
{
    /** @var int */
    private int $envMtime = 0;
    
    /** @var int */
    private int $maxPreviewBytes;

    /**
     * Initializes the ValueTelemetry instance and sets the maximum preview size.
     */
    public function __construct()
    {
        $this->maxPreviewBytes = ValuePreview::maxBytes();
    }

    /**
     * Generates a description of the given value, including its type, size, and an optional preview.
     * 
     * @param mixed $value
     * @return array<string,mixed>
     */
    public function describe(mixed $value): array
    {
        $payload = [
            'value_type' => gettype($value),
        ];

        $serialized = @serialize($value);
        if ($serialized !== false) {
            $payload['size_bytes'] = strlen($serialized);
        }

        if ($this->shouldCaptureValues()) {
            $payload['value_preview'] = ValuePreview::build($value, $this->maxPreviewBytes);
        }

        return $payload;
    }

    /**
     * Determines whether value previews should be captured based on the environment variable.
     *
     * @return bool
     */
    private function shouldCaptureValues(): bool
    {
        $envFile = Env::root() . DIRECTORY_SEPARATOR . '.env';
        $mtime = is_file($envFile) ? (int) @filemtime($envFile) : 0;

        if ($mtime !== $this->envMtime) {
            $this->envMtime = $mtime;
            Env::reload();
        }

        return Env::getBool('CACHEER_MONITOR_CAPTURE_VALUES');
    }
}
