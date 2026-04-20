<?php

declare(strict_types=1);

namespace Cacheer\Monitor;

use Cacheer\Monitor\Reporter\MetricsReporterInterface;
use Cacheer\Monitor\Support\ValueTelemetry;
use Silviooosilva\CacheerPhp\Cacheer;

/**
 * Lightweight wrapper that instruments a Cacheer instance without modifying the core.
 * Note: Configure your Cacheer first, then wrap it for best chaining behavior.
 */
final class InstrumentedCacheer
{
    private ValueTelemetry $valueTelemetry;

    /**
     * @param Cacheer $inner The Cacheer instance to wrap
     * @param MetricsReporterInterface $reporter The reporter to log events to
     */
    public function __construct(
        private Cacheer $inner,
        private MetricsReporterInterface $reporter,
    ) {
        $this->valueTelemetry = new ValueTelemetry();
    }

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

        if (!empty($args)) {
            if (isset($args[0]) && is_string($args[0])) {
                $payload['key'] = $args[0];
            }
            [$ns, $ttl] = $this->extractNsAndTtl($method, $args);
            if ($ns !== null)  { $payload['namespace'] = $ns; }
            if ($ttl !== null) { $payload['ttl']       = $ttl; }
        }

        if ($type === 'hit') {
            $payload = array_merge($payload, $this->valueTelemetry->describe($result));
        }
        if ($type === 'put' && isset($args[1])) {
            $payload = array_merge($payload, $this->valueTelemetry->describe($args[1]));
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
     * Extract namespace and TTL from the method arguments.
     *
     * Each method in CacheerPHP has a different positional layout:
     *   putCache / add         → (key, value, namespace, ttl)
     *   renewCache             → (key, ttl, namespace)
     *   remember               → (key, ttl, callback)
     *   getCache / clearCache  → (key, namespace, ttl?)
     *
     * When TTL is absent from the call, we fall back to the instance-level
     * expirationTime option set via OptionBuilder (e.g. ->day(30)).
     *
     * @return array{0: string|null, 1: int|string|null}
     */
    private function extractNsAndTtl(string $method, array $args): array
    {
        return match ($method) {
            // (key, value, namespace='', ttl=3600)
            'putCache', 'add' => [
                isset($args[2]) && is_string($args[2]) ? $args[2] : null,
                isset($args[3]) && (is_string($args[3]) || is_int($args[3]))
                    ? $args[3]
                    : $this->resolveDefaultTtl(),
            ],
            // (key, ttl=3600, namespace='')
            'renewCache', 'remember', 'rememberForever' => [
                isset($args[2]) && is_string($args[2]) ? $args[2] : null,
                isset($args[1]) && (is_string($args[1]) || is_int($args[1])) ? $args[1] : null,
            ],
            // (key, value) — no namespace or TTL
            'forever', 'appendCache' => [null, null],
            // Default: (key, namespace='', ttl?)
            default => [
                isset($args[1]) && is_string($args[1]) ? $args[1] : null,
                isset($args[2]) && (is_string($args[2]) || is_int($args[2])) ? $args[2] : null,
            ],
        };
    }

    /**
     * Read the TTL configured via OptionBuilder (e.g. ->expirationTime()->day(30))
     * and convert it to seconds. Returns null when not set or not parseable.
     */
    private function resolveDefaultTtl(): ?int
    {
        try {
            $options    = property_exists($this->inner, 'options') ? $this->inner->options : [];
            $expiration = $options['expirationTime'] ?? null;
            if (!is_string($expiration) || $expiration === '') {
                return null;
            }
            return $this->parseExpirationString($expiration);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Convert a TimeBuilder string like "30 days" or "2 hours" to seconds.
     */
    private function parseExpirationString(string $expiration): ?int
    {
        if (!preg_match('/^(\d+)\s+(second|minute|hour|day|week|month)s?$/i', trim($expiration), $m)) {
            return null;
        }

        $value = (int) $m[1];
        $unit  = strtolower($m[2]);

        $multipliers = [
            'second' => 1,
            'minute' => 60,
            'hour'   => 3600,
            'day'    => 86400,
            'week'   => 604800,
            'month'  => 2592000,
        ];

        return isset($multipliers[$unit]) ? $value * $multipliers[$unit] : null;
    }

    /**
     * Attempt to resolve the current cache driver name.
     */
    private function resolveDriver(): string
    {
        try {
            if (method_exists($this->inner, 'getCacheStore')) {
                $store = $this->inner->getCacheStore();
            } elseif (property_exists($this->inner, 'cacheStore')) {
                $store = $this->inner->cacheStore;
            } else {
                return 'unknown';
            }

            if ($store === null) {
                return 'unknown';
            }

            return (new \ReflectionClass($store))->getShortName();
        } catch (\Throwable) {
            return 'unknown';
        }
    }

}
