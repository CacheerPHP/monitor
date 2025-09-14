<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Console;

use Cacheer\Monitor\Console\Commands\ServeCommand;

final class Application
{
    /** @var array<string, callable> */
    private array $commands = [];

    public function __construct()
    {
        $this->register('serve', [new ServeCommand(), 'run']);
        $this->register('help', function (): int {
            $this->printHelp();
            return 0;
        });
    }

    /**
     * @param string $name
     * @param callable(array<string,string|int|null>):int $handler
     */
    public function register(string $name, callable $handler): void
    {
        $this->commands[$name] = $handler;
    }

    public function run(array $argv): int
    {
        array_shift($argv); // script path
        $cmd = $argv[0] ?? 'help';
        $args = $this->parseOptions(array_slice($argv, 1));
        if (!isset($this->commands[$cmd])) {
            $this->printHelp();
            return 1;
        }
        return (int) call_user_func($this->commands[$cmd], $args);
    }

    /**
     * @param list<string> $args
     * @return array<string,string|int|null>
     */
    private function parseOptions(array $args): array
    {
        $opts = [
            'host' => '127.0.0.1',
            'port' => 9966,
            'events' => null,
        ];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--host=')) { $opts['host'] = substr($arg, 7); }
            elseif (str_starts_with($arg, '--port=')) { $opts['port'] = (int) substr($arg, 7); }
            elseif (str_starts_with($arg, '--events=')) { $opts['events'] = substr($arg, 9); }
        }
        return $opts;
    }

    private function printHelp(): void
    {
        echo "Cacheer Monitor CLI\n\n";
        echo "Commands:\n";
        echo "  serve           Start the local dashboard server\n\n";
        echo "Options:\n";
        echo "  --host=127.0.0.1  Bind address (default 127.0.0.1)\n";
        echo "  --port=9966       Port (default 9966)\n";
        echo "  --events=/path    JSONL events file path (default system temp or .env)\n\n";
    }
}

