<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Support;

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

    /** Get the package root directory.
     * 
     * @return string Absolute path to the package root
     */
    public static function root(): string
    {
        // cacheer-monitor/src/Support/Env.php -> repo root is two levels up from 'server'
        // Using composer baseDir: assume this file lives under repo/cacheer-monitor/src/...
        return dirname(__DIR__, 2);
    }
}

