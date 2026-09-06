<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Console\Commands;

use Cacheer\Monitor\Support\Env;

/**
 * ServeCommand boots the local dashboard with PHP's built-in server.
 */
final class ServeCommand
{
    /** @var int Worker processes used when the platform can fork. */
    private const DEFAULT_WORKERS = 4;

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
        putenv('CACHEER_AUTOLOAD=' . Env::root() . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php');

        $workers = $this->determineWorkers($args['workers'] ?? null);
        if ($workers > 1) {
            putenv('PHP_CLI_SERVER_WORKERS=' . $workers);
        }

        $commandLine = $this->serverCmd($host, $port);
        if (!$quiet) {
            fwrite(STDOUT, "Cacheer Monitor starting...\n");
            fwrite(STDOUT, "- Events file: {$eventsPath}\n");
            fwrite(STDOUT, "- URL: http://{$host}:{$port}\n");
            fwrite(STDOUT, $workers > 1
                ? "- Workers: {$workers}\n\n"
                : "- Workers: 1 (the live stream will block other requests;"
                    . " concurrent workers are unavailable on this platform)\n\n");
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
     * Resolve how many worker processes the built-in server should fork.
     *
     * The built-in server is single-process by default, so one open SSE stream
     * occupies it for the whole timeout and every other request queues behind
     * it. Forking workers keeps the dashboard responsive while a stream is held
     * open. Windows has no fork, so it stays single-process there.
     *
     * @param string|null $opt
     * @return int Worker count, or 1 when concurrency is unavailable
     */
    private function determineWorkers(?string $opt): int
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return 1;
        }
        $value = $opt ?? Env::get('CACHEER_MONITOR_WORKERS');
        $workers = ($value === null || $value === '') ? self::DEFAULT_WORKERS : (int) $value;
        return max(1, min($workers, 32));
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
     * Resolve a path against the consuming project root.
     * 
     * @param string $path
     * @return string
     */
    private function resolvePath(string $path): string
    {
        $isAbsolute = (bool) preg_match('#^([A-Za-z]:\\\\|/|\\\\\\\\)#', $path);
        return $isAbsolute ? $path : (Env::root() . DIRECTORY_SEPARATOR . ltrim($path, '/\\'));
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
