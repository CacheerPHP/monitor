<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Support;

use Composer\Autoload\ClassLoader;

final class Env
{
    /** @var bool */
    private static bool $loaded = false;

    /** @var array<string,mixed> */
    private static array $vars = [];

    /** Get an environment variable, checking OS env first, then .env file.
     *
     * @param string $key Environment variable name
     * @param mixed $default Default value if not set
     * @return mixed Value of the environment variable or default
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::boot();
        $v = getenv($key);
        if ($v !== false && $v !== null && $v !== '') {
            return $v;
        }
        return self::$vars[$key] ?? $default;
    }

    /**
     * Load .env file if not already loaded
     * 
     * @return void
     */
    private static function boot(): void
    {
        if (self::$loaded) return;
        self::$loaded = true;
        $envPath = self::root() . DIRECTORY_SEPARATOR . '.env';
        if (!is_file($envPath)) return;
        $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            $pos = strpos($line, '=');
            if ($pos === false) continue;
            $k = trim(substr($line, 0, $pos));
            $v = trim(substr($line, $pos + 1));
            if (($v[0] ?? '') === '"' && str_ends_with($v, '"')) { $v = substr($v, 1, -1); }
            if (($v[0] ?? '') === "'" && str_ends_with($v, "'")) { $v = substr($v, 1, -1); }
            self::$vars[$k] = $v;
        }
    }

    /** Get the project root directory.
     *
     * Traverses upward from this file looking for a composer.json or .env,
     * which reliably identifies the root whether the package is used standalone
     * or installed as a Composer dependency (vendor/cacheerphp/monitor/...).
     * Falls back to getcwd() so running the binary from the project root always works.
     *
     * @return string Absolute path to the project root
     */
    public static function root(): string
    {
        $autoloadRoot = self::autoloadRoot();
        if ($autoloadRoot !== null) {
            return $autoloadRoot;
        }

        $cwd = rtrim((string) getcwd(), DIRECTORY_SEPARATOR);
        if (self::isProjectRoot($cwd)) {
            return $cwd;
        }

        // Fallback: traverse upward from __DIR__ (works in standalone/dev mode).
        $dir = __DIR__;
        while ($dir !== dirname($dir)) {
            if (self::isProjectRoot($dir)) {
                return rtrim($dir, DIRECTORY_SEPARATOR);
            }
            $dir = dirname($dir);
        }

        return $cwd;
    }

    /**
     * Resolve the consuming app root from the active Composer autoloader.
     *
     * @return string|null
     */
    private static function autoloadRoot(): ?string
    {
        $explicitAutoload = getenv('CACHEER_AUTOLOAD');
        if (is_string($explicitAutoload) && $explicitAutoload !== '') {
            $root = self::projectRootFromAutoloadPath($explicitAutoload);
            if ($root !== null) {
                return $root;
            }
        }

        if (class_exists(\Composer\Autoload\ClassLoader::class)) {
            foreach (ClassLoader::getRegisteredLoaders() as $vendorDir => $_loader) {
                if (!is_string($vendorDir)) {
                    continue;
                }

                $root = self::projectRootFromVendorDir($vendorDir);
                if ($root !== null) {
                    return $root;
                }
            }
        }

        return null;
    }

    /**
     * @param string $path
     * @return string|null
     */
    private static function projectRootFromAutoloadPath(string $path): ?string
    {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        if (basename($normalized) !== 'autoload.php') {
            return null;
        }

        return self::projectRootFromVendorDir(dirname($normalized));
    }

    /**
     * @param string $vendorDir
     * @return string|null
     */
    private static function projectRootFromVendorDir(string $vendorDir): ?string
    {
        $normalized = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $vendorDir), DIRECTORY_SEPARATOR);
        if (basename($normalized) !== 'vendor') {
            return null;
        }

        $root = dirname($normalized);
        return $root !== '' ? $root : null;
    }

    /**
     * @param string $dir
     * @return bool
     */
    private static function isProjectRoot(string $dir): bool
    {
        return file_exists(rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php');
    }
}
