<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Application;

use Cacheer\Monitor\Support\Env;
use Cacheer\Monitor\Support\ValuePreview;
use PDO;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Exceptions\InvalidKeyException;
use Silviooosilva\CacheerPhp\Exceptions\InvalidScopeException;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Scope;
use Silviooosilva\CacheerPhp\Stores\DatabaseStore;
use Silviooosilva\CacheerPhp\Stores\FileStore;
use Silviooosilva\CacheerPhp\Stores\RedisStore;
use Silviooosilva\CacheerPhp\Stores\Support\PhpRedisConnection;
use Silviooosilva\CacheerPhp\Stores\Support\PredisConnection;

/**
 * Resolves a fresh value preview directly from the active cache backend.
 *
 * The dashboard runs in a different process from the application, so to read a
 * live value it reconstructs a store over the same backend from
 * the shared connection settings in the environment (DB_*, REDIS_*, and
 * CACHEER_MONITOR_CACHE_PATH for the file store). Direct Store::get() calls keep
 * these preview reads out of the telemetry stream.
 *
 * This is a best-effort fallback; the primary source of value previews is the
 * event stream itself (captured in the app process when CACHEER_MONITOR_CAPTURE_VALUES
 * is on). Live reads only succeed for backends reachable from the dashboard and
 * for values written with the default storage pipeline and key encoder.
 */
final class LiveCachePreviewer
{
    /**
     * @return array<string,mixed>|null
     */
    public function preview(string $key, ?string $namespace = null, ?string $driverHint = null): ?array
    {
        return $this->lookup($key, $namespace, $driverHint)['preview'];
    }

    /**
     * @return array{preview:array<string,mixed>|null,reason:string|null}
     */
    public function lookup(string $key, ?string $namespace = null, ?string $driverHint = null): array
    {
        try {
            $store = $this->resolveStore($driverHint);
            if ($store === null) {
                return ['preview' => null, 'reason' => match ($driverHint) {
                    'FileStore', 'FileCacheStore' => 'Live lookup requires CACHEER_MONITOR_CACHE_PATH to point to the application\'s cache directory.',
                    'DatabaseStore', 'DatabaseCacheStore' => 'Live lookup requires the application\'s database connection settings.',
                    'RedisStore', 'RedisCacheStore' => 'Live lookup requires a Redis client and the application\'s Redis connection settings.',
                    'ArrayStore', 'ArrayCacheStore' => 'This cache lives in the application process and cannot be read by the dashboard. Enable value capture in the application to record previews.',
                    default => 'No supported cache backend was reported for this key. Enable value capture in the application to record previews.',
                }];
            }

            $match = null;
            foreach ($this->candidateKeys($key, $namespace) as $candidate) {
                $entry = $store->get($candidate);
                if (!$entry->isHit()) {
                    continue;
                }
                if ($match !== null) {
                    return ['preview' => null, 'reason' => 'Multiple stored keys share this display name. A live preview cannot safely identify the value; use a captured event preview.'];
                }
                $match = $entry;
            }

            if ($match === null) {
                return ['preview' => null, 'reason' => 'The key was not found in the configured backend. It may have expired or been removed. Check that the backend and storage pipeline match the application.'];
            }

            $value = $match->value();

            $preview = [
                'last_value_preview' => ValuePreview::build($value),
                'preview_source'     => 'live',
                'last_value_type'    => gettype($value),
            ];

            $serialized = @serialize($value);
            if ($serialized !== false) {
                $preview['last_size_bytes'] = strlen($serialized);
            }

            return ['preview' => $preview, 'reason' => null];
        } catch (\Throwable) {
            // Do not expose exception messages: connection errors can contain credentials.
            return ['preview' => null, 'reason' => 'Live lookup failed. Check the backend connection. Custom codecs, compression, encryption, or key encoders require captured event previews.'];
        }
    }

    /**
     * v6 events contain a display name, which can mean either a literal root key
     * or a scoped key. Check each valid identity and accept only a unique hit.
     * This uses at most the root plus 64 scope prefixes, without scanning a store.
     * An explicit legacy namespace already identifies the key precisely.
     *
     * @return \Generator<int,Key>
     */
    private function candidateKeys(string $key, ?string $namespace): \Generator
    {
        if ($namespace !== null) {
            $scope = $namespace === '' || $namespace === '(default)'
                ? Scope::root()
                : Scope::fromSegments(explode('/', $namespace));
            yield Key::named($key)->within($scope);
            return;
        }

        try {
            yield Key::named($key);
        } catch (InvalidKeyException) {
            // A scoped display name can exceed the byte limit of a raw key.
        }

        $scope = Scope::root();
        $offset = 0;
        while (($slash = strpos($key, '/', $offset)) !== false) {
            try {
                $scope = $scope->child(substr($key, $offset, $slash - $offset));
            } catch (InvalidScopeException) {
                break;
            }
            try {
                yield Key::named(substr($key, $slash + 1))->within($scope);
            } catch (InvalidKeyException) {
                // Skip empty or oversized suffixes; later splits may be valid.
            }
            $offset = $slash + 1;
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
