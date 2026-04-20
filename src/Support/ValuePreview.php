<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Support;

/**
 * Build compact JSON-safe previews for cached values.
 */
final class ValuePreview
{
    /** @var list<string> */
    private const DEFAULT_REDACT_KEYS = [
        'password',
        'passwd',
        'pwd',
        'secret',
        'token',
        'access_token',
        'refresh_token',
        'authorization',
        'api_key',
        'apikey',
        'private_key',
        'client_secret',
        'cookie',
        'session',
    ];

    private const MAX_DEPTH = 6;

    /**
     * @return int
     */
    public static function maxBytes(): int
    {
        return (int) (Env::get('CACHEER_MONITOR_PREVIEW_BYTES') ?: 2048);
    }

    /**
     * @param mixed $value
     * @param int|null $maxBytes
     * @return string
     */
    public static function build(mixed $value, ?int $maxBytes = null): string
    {
        $seen = [];
        $normalized = self::sanitize($value, self::redactKeys(), 0, $seen);
        $json = @json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false || $json === null) {
            $json = json_encode(['__type' => gettype($value), '__repr' => @strval($value)]);
        }

        $limit = $maxBytes ?? self::maxBytes();
        if (strlen((string) $json) > $limit) {
            return substr((string) $json, 0, $limit) . '… [truncated]';
        }

        return (string) $json;
    }

    /**
     * @return list<string>
     */
    private static function redactKeys(): array
    {
        $configured = array_filter(array_map(
            static fn(string $part): string => strtolower(trim($part)),
            explode(',', (string) Env::get('CACHEER_MONITOR_REDACT_KEYS', ''))
        ));

        return array_values(array_unique(array_merge(self::DEFAULT_REDACT_KEYS, $configured)));
    }

    /**
     * @param mixed $value
     * @param list<string> $redactKeys
     * @param int $depth
     * @param array<int, bool> $seenObjects
     * @return mixed
     */
    private static function sanitize(mixed $value, array $redactKeys, int $depth, array &$seenObjects): mixed
    {
        if ($depth >= self::MAX_DEPTH) {
            return '[max depth reached]';
        }

        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && self::isSensitiveKey($key, $redactKeys)) {
                    $sanitized[$key] = '[redacted]';
                    continue;
                }

                $sanitized[$key] = self::sanitize($item, $redactKeys, $depth + 1, $seenObjects);
            }

            return $sanitized;
        }

        if (is_object($value)) {
            $objectId = spl_object_id($value);
            if (isset($seenObjects[$objectId])) {
                return ['__class' => $value::class, '__repr' => '[circular reference]'];
            }

            $seenObjects[$objectId] = true;
            try {
                $payload = $value instanceof \JsonSerializable ? $value->jsonSerialize() : get_object_vars($value);
                $sanitizedPayload = self::sanitize($payload, $redactKeys, $depth + 1, $seenObjects);

                if ($value instanceof \stdClass) {
                    return $sanitizedPayload;
                }

                return [
                    '__class' => $value::class,
                    '__value' => $sanitizedPayload,
                ];
            } catch (\Throwable) {
                return ['__class' => $value::class, '__repr' => '[unserializable object]'];
            } finally {
                unset($seenObjects[$objectId]);
            }
        }

        if (is_resource($value)) {
            return ['__type' => 'resource'];
        }

        return ['__type' => gettype($value)];
    }

    /**
     * @param string $key
     * @param list<string> $redactKeys
     * @return bool
     */
    private static function isSensitiveKey(string $key, array $redactKeys): bool
    {
        $normalized = strtolower(trim($key));
        foreach ($redactKeys as $candidate) {
            if ($candidate === '') {
                continue;
            }

            if ($normalized === $candidate || str_contains($normalized, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
