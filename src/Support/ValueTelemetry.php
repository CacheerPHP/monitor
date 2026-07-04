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

        $size = $this->sizeOf($value);
        if ($size !== null) {
            $payload['size_bytes'] = $size;
        }

        if ($this->shouldCaptureValues()) {
            $payload['value_preview'] = ValuePreview::build($value, $this->maxPreviewBytes);
        }

        return $payload;
    }

    /**
     * Approximate the byte size of a value for telemetry.
     *
     * Runs in the host application's request path on every cached read/write,
     * so the common (and often largest) case — string payloads like HTML
     * fragments or JSON blobs — is measured with strlen() in O(1) rather than
     * paying serialize()'s full copy. Composite values fall back to serialize().
     *
     * @param mixed $value
     * @return int|null
     */
    private function sizeOf(mixed $value): ?int
    {
        if (is_string($value)) {
            return strlen($value);
        }

        $serialized = @serialize($value);
        return $serialized !== false ? strlen($serialized) : null;
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
