<?php

declare(strict_types=1);

namespace Cacheer\Monitor;

use Cacheer\Monitor\Reporter\MetricsReporterInterface;
use Silviooosilva\CacheerPhp\Cacheer;

/**
 * Lightweight wrapper that instruments a Cacheer instance without modifying the core.
 * Note: Configure your Cacheer first, then wrap it for best chaining behavior.
 */
final class InstrumentedCacheer
{
    /** @var Cacheer */
    private Cacheer $inner;

    /** @var MetricsReporterInterface */
    private MetricsReporterInterface $reporter;

    /** Constructor
     *
     * @param Cacheer $inner The Cacheer instance to wrap
     * @param MetricsReporterInterface $reporter The reporter to log events to
     */
    public function __construct(Cacheer $inner, MetricsReporterInterface $reporter)
    {
        $this->inner = $inner;
        $this->reporter = $reporter;
    }

    /** Wrap a Cacheer instance with instrumentation.
     *
     * @param Cacheer $inner The Cacheer instance to wrap
     * @param MetricsReporterInterface $reporter The reporter to log events to
     * @return self The wrapped InstrumentedCacheer instance
     */
    public static function wrap(Cacheer $inner, MetricsReporterInterface $reporter): self
    {
        return new self($inner, $reporter);
    }   

    /** Get the inner Cacheer instance.
     *
     * @return Cacheer The wrapped Cacheer instance
     */
    public function inner(): Cacheer
    {
        return $this->inner;
    }

    /** Magic method to forward calls to the inner Cacheer instance with instrumentation.
     *
     * @param string $name The method name being called
     * @param array $arguments The arguments passed to the method
     * @return mixed The result of the inner method call
     * @throws \Throwable Any exception thrown by the inner method
     */
    public function __call(string $name, array $arguments): mixed
    {
        $start = microtime(true);
        try {
            $result = $this->inner->{$name}(...$arguments);
            $duration = (microtime(true) - $start) * 1000.0;
            $this->maybeReport($name, $arguments, $result, $duration, null);
            return $result;
        } catch (\Throwable $e) {
            $duration = (microtime(true) - $start) * 1000.0;
            $this->reporter->event('error', [
                'method' => $name,
                'message' => $e->getMessage(),
                'driver' => $this->resolveDriver(),
                'duration_ms' => $duration,
            ]);
            throw $e;
        }
    }

    /** Decide whether to report an event based on method and result.
     *
     * @param string $method The method name called
     * @param array $args The arguments passed to the method
     * @param mixed $result The result returned by the method
     * @param float $durationMs Duration of the call in milliseconds
     * @param string|null $overrideType Optional event type override
     * @return void
     */
    private function maybeReport(string $method, array $args, mixed $result, float $durationMs, ?string $overrideType): void
    {
        $type = $overrideType ?? $this->resolveEventType($method);
        if ($type === null) {
            return; // skip config/utility methods
        }

        $payload = [
            'method' => $method,
            'driver' => $this->resolveDriver(),
            'duration_ms' => $durationMs,
            'success' => $this->inner->isSuccess(),
        ];

        // Common positional args: (key, namespace, ttl)
        if (!empty($args)) {
            if (isset($args[0]) && is_string($args[0])) {
                $payload['key'] = $args[0];
            }
            if (isset($args[1]) && is_string($args[1])) {
                $payload['namespace'] = $args[1];
            }
            if (isset($args[2]) && (is_string($args[2]) || is_int($args[2]))) {
                $payload['ttl'] = $args[2];
            }
        }

        if ($type === 'hit') {
            $payload['size_bytes'] = $this->estimateSize($result);
        }
        if ($type === 'put' && isset($args[1])) {
            // putCache(key, data, ...)
            $payload['size_bytes'] = $this->estimateSize($args[1]);
        }

        if ($type === 'put_many' && isset($args[0]) && is_array($args[0])) {
            $payload['count'] = count($args[0]);
        }

        if ($type === 'has' && is_bool($result)) {
            $payload['exists'] = $result;
        }

        $this->reporter->event($type, $payload);
    }


    /**
     * Resolve the event type for a given method.
     *
     * @param string $method The method name
     * @return string|null The resolved event type, or null if not applicable
     */
    private function resolveEventType(string $method): ?string
    {
        // Normalize methods to event types
        return match ($method) {
            'getCache' => $this->inner->isSuccess() ? 'hit' : 'miss',
            'getMany' => 'get_many',
            'getAll' => 'get_all',
            'has' => 'has',
            'putCache' => 'put',
            'putMany' => 'put_many',
            'appendCache' => 'append',
            'forever' => 'put_forever',
            'clearCache' => 'clear',
            'flushCache' => 'flush',
            'renewCache' => 'renew',
            'tag' => 'tag',
            'flushTag' => 'flush_tag',
            'remember' => 'remember',
            'rememberForever' => 'remember_forever',
            default => $this->isConfigLike($method) ? null : 'call',
        };
    }

    /** Check if a method is configuration-related.
     *
     * @param string $method The method name
     * @return bool True if the method is configuration-related, false otherwise
     */
    private function isConfigLike(string $method): bool
    {
        return in_array($method, [
            'setConfig', 'setDriver', 'setUp', 'getOptions', 'useEncryption', 'useCompression', 'useFormatter'
        ], true);
    }

    /** Attempt to resolve the current cache driver name.
     *
     * @return string The driver name, or 'unknown' if it cannot be determined
     */
    private function resolveDriver(): string
    {
        try {
            $store = $this->inner->cacheStore ?? null;
            if ($store === null) {
                return 'unknown';
            }
            $ref = new \ReflectionClass($store);
            return $ref->getShortName();
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    /** Estimate the size in bytes of a value by serializing it.
     *
     * @param mixed $value The value to estimate
     * @return int|null The estimated size in bytes, or null if it cannot be determined
     */
    private function estimateSize(mixed $value): ?int
    {
        try {
            return strlen(serialize($value));
        } catch (\Throwable) {
            return null;
        }
    }
}

