<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Application;

use Cacheer\Monitor\Support\Env;
use Cacheer\Monitor\Support\ValuePreview;
use PDO;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Stores\DatabaseStore;
use Silviooosilva\CacheerPhp\Stores\FileStore;
use Silviooosilva\CacheerPhp\Stores\RedisStore;
use Silviooosilva\CacheerPhp\Stores\Support\PhpRedisConnection;
use Silviooosilva\CacheerPhp\Stores\Support\PredisConnection;

/**
 * Resolves a fresh value preview directly from the active cache backend.
 *
 * The dashboard runs in a different process from the application, so to read a
 * live value it reconstructs an uninstrumented reader over the same backend from
 * the shared connection settings in the environment (DB_*, REDIS_*, and
 * CACHEER_MONITOR_CACHE_PATH for the file store). Using a plain `new Cacheer()`
 * — not the auto-instrumented named constructors — keeps these preview reads out
 * of the telemetry stream.
 *
 * This is a best-effort fallback; the primary source of value previews is the
 * event stream itself (captured in the app process when CACHEER_MONITOR_CAPTURE_VALUES
 * is on). Live reads only succeed for backends reachable from the dashboard and
 * for values not written through an encrypted pipeline.
 */
final class LiveCachePreviewer
{
    /**
     * @return array<string,mixed>|null
     */
    public function preview(string $key, ?string $namespace = null, ?string $driverHint = null): ?array
    {
        try {
            $store = $this->resolveStore($driverHint);
            if ($store === null) {
                return null;
            }

            // Uninstrumented on purpose: previewing must not emit cache events.
            $cache = new Cacheer($store);

            $resolved = $namespace === null || $namespace === '' || $namespace === '(default)'
                ? $cache
                : $cache->scope($namespace);

            $entry = $resolved->entry($key);
            if (!$entry->isHit()) {
                return null;
            }

            $value = $entry->value();

            $preview = [
                'last_value_preview' => ValuePreview::build($value),
                'preview_source'     => 'live',
                'last_value_type'    => gettype($value),
            ];

            $serialized = @serialize($value);
            if ($serialized !== false) {
                $preview['last_size_bytes'] = strlen($serialized);
            }

            return $preview;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Rebuild the store implied by the event's driver hint. Accepts both v6 store
     * names (FileStore, …) and the legacy v5 names for older event files. The
     * array store is process-local, so it can never be read from the dashboard.
     */
    private function resolveStore(?string $driverHint): ?Store
    {
        return match ($driverHint) {
            'FileStore', 'FileCacheStore'         => $this->fileStore(),
            'DatabaseStore', 'DatabaseCacheStore' => $this->databaseStore(),
            'RedisStore', 'RedisCacheStore'       => $this->redisStore(),
            default                               => null,
        };
    }

    private function fileStore(): ?Store
    {
        $path = (string) Env::get('CACHEER_MONITOR_CACHE_PATH', '');

        return $path !== '' && is_dir($path) ? new FileStore($path) : null;
    }

    private function databaseStore(): ?Store
    {
        $pdo = $this->buildPdo();
        if ($pdo === null) {
            return null;
        }

        $table = (string) Env::get('CACHEER_MONITOR_CACHE_TABLE', 'cacheer_store');

        return new DatabaseStore($pdo, $table);
    }

    private function redisStore(): ?Store
    {
        $prefix = (string) Env::get('REDIS_NAMESPACE', '') ?: 'cacheer';
        $host = (string) Env::get('REDIS_HOST', '127.0.0.1');
        $port = (int) Env::get('REDIS_PORT', 6379);
        $password = (string) Env::get('REDIS_PASSWORD', '');

        if (class_exists(\Predis\Client::class)) {
            $params = ['host' => $host, 'port' => $port];
            if ($password !== '') {
                $params['password'] = $password;
            }

            return new RedisStore(new PredisConnection(new \Predis\Client($params)), $prefix);
        }

        if (extension_loaded('redis')) {
            $client = new \Redis();
            $client->connect($host, $port);
            if ($password !== '') {
                $client->auth($password);
            }

            return new RedisStore(new PhpRedisConnection($client), $prefix);
        }

        return null;
    }

    private function buildPdo(): ?PDO
    {
        $connection = strtolower((string) Env::get('DB_CONNECTION', 'sqlite'));
        $database = (string) Env::get('DB_DATABASE', '');

        $dsn = match ($connection) {
            'sqlite' => $database !== '' ? "sqlite:{$database}" : null,
            'mysql', 'mariadb' => sprintf(
                'mysql:host=%s;port=%s;dbname=%s',
                (string) Env::get('DB_HOST', '127.0.0.1'),
                (string) Env::get('DB_PORT', '3306'),
                $database,
            ),
            'pgsql', 'postgres', 'postgresql' => sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                (string) Env::get('DB_HOST', '127.0.0.1'),
                (string) Env::get('DB_PORT', '5432'),
                $database,
            ),
            default => null,
        };

        if ($dsn === null) {
            return null;
        }

        $pdo = new PDO(
            $dsn,
            (string) Env::get('DB_USERNAME', '') ?: null,
            (string) Env::get('DB_PASSWORD', '') ?: null,
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }
}
