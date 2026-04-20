<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Application;

use Cacheer\Monitor\CacheerMonitorListener;
use Cacheer\Monitor\Reporter\JsonlReporter;
use Cacheer\Monitor\Support\ValuePreview;
use Silviooosilva\CacheerPhp\Cacheer;

/**
 * Resolves a fresh value preview directly from the active cache backend.
 */
final class LiveCachePreviewer
{
    /**
     * @return array<string,mixed>|null
     */
    public function preview(string $key, ?string $namespace = null, ?string $driverHint = null): ?array
    {
        if (!class_exists(Cacheer::class)) {
            return null;
        }

        $canToggleListeners = method_exists(Cacheer::class, 'removeListeners') && method_exists(Cacheer::class, 'addListener');

        if ($canToggleListeners) {
            Cacheer::removeListeners();
        }

        try {
            $cache = new Cacheer();
            $this->applyDriverHint($cache, $driverHint);

            $resolvedNamespace = $namespace === '(default)' ? '' : (string) ($namespace ?? '');
            $value = $cache->getCache($key, $resolvedNamespace);

            if (!$cache->isSuccess()) {
                return null;
            }

            $preview = [
                'last_value_preview' => ValuePreview::build($value),
                'preview_source' => 'live',
                'last_value_type' => gettype($value),
            ];

            $serialized = @serialize($value);
            if ($serialized !== false) {
                $preview['last_size_bytes'] = strlen($serialized);
            }

            return $preview;
        } catch (\Throwable) {
            return null;
        } finally {
            if ($canToggleListeners) {
                Cacheer::addListener(new CacheerMonitorListener(new JsonlReporter()));
            }
        }
    }

    private function applyDriverHint(Cacheer $cache, ?string $driverHint): void
    {
        match ($driverHint) {
            'ArrayCacheStore' => $cache->setDriver()->useArrayDriver(),
            'DatabaseCacheStore' => $cache->setDriver()->useDatabaseDriver(),
            'RedisCacheStore' => $cache->setDriver()->useRedisDriver(),
            'FileCacheStore' => $cache->setDriver()->useFileDriver(),
            default => null,
        };
    }
}
