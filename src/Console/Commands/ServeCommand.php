<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Console\Commands;

use Cacheer\Monitor\Support\Env;

/**
 * ServeCommand boots the local dashboard with PHP's built-in server.
 */
final class ServeCommand
{
    /**
     * Run the command.
     *
     * @param array<string,int|string|bool|null> $args
     * @return int Exit code
     */
    public function run(array $args): int
    {
        $host = (string) ($args['host'] ?? '127.0.0.1');
        $port = (int) ($args['port'] ?? 9966);
        $quiet = $this->toBool($args['quiet'] ?? false);
        $eventsPath = $this->determineEventsPath($args['events'] ?? null);
        putenv('CACHEER_MONITOR_EVENTS=' . $eventsPath);

        $commandLine = $this->serverCmd($host, $port);
        if (!$quiet) {
            fwrite(STDOUT, "Cacheer Monitor starting...\n");
            fwrite(STDOUT, "- Events file: {$eventsPath}\n");
            fwrite(STDOUT, "- URL: http://{$host}:{$port}\n\n");
        }

        $descriptorStdout = $quiet ? ['file', $this->nullDevice(), 'w'] : STDOUT;
        $descriptorspec = [0 => STDIN, 1 => $descriptorStdout, 2 => STDERR];
        $process = proc_open($commandLine, $descriptorspec, $pipes, $this->packageRoot());
        if (is_resource($process)) {
            proc_close($process);
            return 0;
        }
        fwrite(STDERR, "Couldn't start server automatically.\n");
        fwrite(STDERR, "Run this command from the package root:\n\n{$commandLine}\n");
        return 1;
    }

    /**
     * Build the command line for PHP built-in server.
     *
     * @param string $host
     * @param int $port
     * @return string
     */
    private function serverCmd(string $host, int $port): string
    {
        $docRoot = $this->packageRoot() . '/public';
        $router  = $this->packageRoot() . '/server/router.php';
        return sprintf('php -S %s:%d -t %s %s', escapeshellarg($host), $port, escapeshellarg($docRoot), escapeshellarg($router));
    }

    /**
     * Get the package root directory.
     * 
     * @return string
     */
    private function packageRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Determine events file path from explicit arg, OS env, .env or temp.
     * 
     * @param string|null $opt
     * @return string
     */
    private function determineEventsPath(?string $opt): string
    {
        if ($opt && $opt !== '') {
            return $this->resolvePath((string) $opt);
        }
        $value = getenv('CACHEER_MONITOR_EVENTS') ?: Env::get('CACHEER_MONITOR_EVENTS');
        if ($value) {
            return $this->resolvePath((string) $value);
        }
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cacheer-monitor.jsonl';
    }

    /**
     * Resolve a path against the package root.
     * 
     * @param string $path
     * @return string
     */
    private function resolvePath(string $path): string
    {
        $isAbsolute = (bool) preg_match('#^([A-Za-z]:\\\\|/|\\\\\\\\)#', $path);
        return $isAbsolute ? $path : ($this->packageRoot() . DIRECTORY_SEPARATOR . ltrim($path, '/\\'));
    }

    /**
     * Convert a value to boolean.
     * 
     * @param mixed $value
     * @return bool
     */
    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }
        if (is_int($value)) {
            return $value === 1;
        }
        return false;
    }

    /**
     * Get the null device path for the current OS.
     * @return string
     */
    private function nullDevice(): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    }
}
