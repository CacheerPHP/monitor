<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Support;

final class ConfigResolver
{
    public static function eventsFileWithOrigin(): array
    {
        $origin = 'default';
        $env = Env::get('CACHEER_MONITOR_EVENTS');
        if ($env) {
            $origin = 'env';
            return [Path::resolve((string) $env), $origin];
        }
        // .env value
        $dotenvPath = self::fromDotEnv('CACHEER_MONITOR_EVENTS');
        if ($dotenvPath) {
            $origin = 'dotenv';
            return [Path::resolve($dotenvPath), $origin];
        }
        return [sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cacheer-monitor.jsonl', $origin];
    }

    private static function fromDotEnv(string $key): ?string
    {
        // Env::get already reads .env; but to distinguish origin we read file directly
        $path = Env::root() . DIRECTORY_SEPARATOR . '.env';
        if (!is_file($path)) return null;
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            $pos = strpos($line, '=');
            if ($pos === false) continue;
            $k = trim(substr($line, 0, $pos));
            $v = trim(substr($line, $pos + 1));
            if (($v[0] ?? '') === '"' && str_ends_with($v, '"')) { $v = substr($v, 1, -1); }
            if (($v[0] ?? '') === "'" && str_ends_with($v, "'")) { $v = substr($v, 1, -1); }
            if ($k === $key) return $v !== '' ? $v : null;
        }
        return null;
    }
}

