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
    /**
     * @param Cacheer $inner The Cacheer instance to wrap
     * @param MetricsReporterInterface $reporter The reporter to log events to
     */
    public function __construct(
        private Cacheer $inner,
        private MetricsReporterInterface $reporter,
    ) {}

    /**
     * Wrap a Cacheer instance with instrumentation.
     */
    public static function wrap(Cacheer $inner, MetricsReporterInterface $reporter): self
    {
        return new self($inner, $reporter);
    }

    /**
     * Get the inner Cacheer instance.
     */
    public function inner(): Cacheer
    {
        return $this->inner;
    }

    /**
     * Forward calls to the inner Cacheer instance with instrumentation.
     *
     * @throws \Throwable Any exception thrown by the inner method
     */
    public function __call(string $name, array $arguments): mixed
    {
        $start = microtime(true);
        try {
            $result = $this->inner->{$name}(...$arguments);
            $duration = (microtime(true) - $start) * 1000.0;
            $this->maybeReport($name, $arguments, $result, $duration);
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

    /**
     * Decide whether to report an event based on method and result.
     */
    private function maybeReport(string $method, array $args, mixed $result, float $durationMs): void
    {
        $type = $this->resolveEventType($method);
        if ($type === null) {
            return;
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
     */
    private function resolveEventType(string $method): ?string
    {
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
            'add' => 'add',
            'increment' => 'increment',
            'decrement' => 'decrement',
            'getAndForget' => 'get_and_forget',
            default => $this->isConfigLike($method) ? null : 'call',
        };
    }

    /**
     * Check if a method is configuration-related (not instrumented).
     */
    private function isConfigLike(string $method): bool
    {
        return in_array($method, [
            'setConfig', 'setDriver', 'setUp', 'getOptions', 'setOption', 'setOptions',
            'useEncryption', 'useCompression', 'useFormatter',
            'getCacheStore', 'setCacheStore',
            'stats', 'resetInstance', 'setInstance',
        ], true);
    }

    /**
     * Attempt to resolve the current cache driver name.
     */
    private function resolveDriver(): string
    {
        try {
            $store = method_exists($this->inner, 'getCacheStore')
                ? $this->inner->getCacheStore()
                : null;
            if ($store === null) {
                return 'unknown';
            }
            $ref = new \ReflectionClass($store);
            return $ref->getShortName();
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    /**
     * Estimate the size in bytes of a value by serializing it.
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
