<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Support;

/**
 * Resolve configuration values used by Cacheer Monitor.
 */
final class ConfigResolver
{
    /**
     * Resolve the events file path and its origin.
     * Order: OS env (env) -> .env (dotenv) -> temp (default).
     *
     * @return array{0:string,1:string} [path, origin]
     */
    public static function eventsFileWithOrigin(): array
    {
        $origin = 'default';
        // Use OS environment only for 'env' origin, not .env fallback
        $osEnvValue = getenv('CACHEER_MONITOR_EVENTS');
        if ($osEnvValue !== false && $osEnvValue !== null && $osEnvValue !== '') {
            $origin = 'env';
            return [Path::resolve((string) $osEnvValue), $origin];
        }
        // .env value
        $dotEnvPath = self::fromDotEnv('CACHEER_MONITOR_EVENTS');
        if ($dotEnvPath) {
            $origin = 'dotenv';
            return [Path::resolve($dotEnvPath), $origin];
        }
        return [sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cacheer-monitor.jsonl', $origin];
    }

    /**
     * Read a key directly from the .env file (no OS fallback) and return its value.
     *
     * @param string $key
     * @return string|null
     */
    private static function fromDotEnv(string $key): ?string
    {
        // Env::get already reads .env; but to distinguish origin we read file directly
        $envFilePath = Env::root() . DIRECTORY_SEPARATOR . '.env';
        if (!is_file($envFilePath)) {
            return null;
        }
        $lines = @file($envFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $delimiterPos = strpos($line, '=');
            if ($delimiterPos === false) {
                continue;
            }
            $foundKey = trim(substr($line, 0, $delimiterPos));
            $foundValue = trim(substr($line, $delimiterPos + 1));
            if ((($foundValue[0] ?? '') === '"' && str_ends_with($foundValue, '"')) || (($foundValue[0] ?? '') === "'" && str_ends_with($foundValue, "'"))) {
                $foundValue = substr($foundValue, 1, -1);
            }
            if ($foundKey === $key) {
                return $foundValue !== '' ? $foundValue : null;
            }
        }
        return null;
    }
}
