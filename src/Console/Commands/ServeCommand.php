<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Console\Commands;

use Cacheer\Monitor\Support\Env;

final class ServeCommand
{
    /**
     * @param array<string,string|int|null> $args
     */
    public function run(array $args): int
    {
        $host = (string) ($args['host'] ?? '127.0.0.1');
        $port = (int) ($args['port'] ?? 9966);
        $events = $this->determineEventsPath($args['events'] ?? null);
        putenv('CACHEER_MONITOR_EVENTS=' . $events);

        $cmdLine = $this->serverCmd($host, $port);
        fwrite(STDOUT, "Cacheer Monitor starting...\n");
        fwrite(STDOUT, "- Events file: {$events}\n");
        fwrite(STDOUT, "- URL: http://{$host}:{$port}\n\n");

        $descriptorspec = [0 => STDIN, 1 => STDOUT, 2 => STDERR];
        $proc = proc_open($cmdLine, $descriptorspec, $pipes, $this->packageRoot());
        if (is_resource($proc)) {
            proc_close($proc);
            return 0;
        }
        fwrite(STDOUT, "Couldn't start server automatically.\n");
        fwrite(STDOUT, "Run this command from the package root:\n\n{$cmdLine}\n");
        return 1;
    }

    private function serverCmd(string $host, int $port): string
    {
        $docRoot = $this->packageRoot() . '/public';
        $router  = $this->packageRoot() . '/server/router.php';
        return sprintf('php -S %s:%d -t %s %s', escapeshellarg($host), $port, escapeshellarg($docRoot), escapeshellarg($router));
    }

    private function packageRoot(): string
    {
        return Env::root();
    }

    private function determineEventsPath(?string $opt): string
    {
        if ($opt && $opt !== '') {
            return $this->resolvePath((string) $opt);
        }
        $val = getenv('CACHEER_MONITOR_EVENTS') ?: Env::get('CACHEER_MONITOR_EVENTS');
        if ($val) {
            return $this->resolvePath((string) $val);
        }
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cacheer-monitor.jsonl';
    }

    private function resolvePath(string $path): string
    {
        $isAbsolute = (bool) preg_match('#^([A-Za-z]:\\\\|/|\\\\\\\\)#', $path);
        return $isAbsolute ? $path : ($this->packageRoot() . DIRECTORY_SEPARATOR . ltrim($path, '/\\'));
    }
}

