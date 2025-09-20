<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Console;

use Cacheer\Monitor\Console\Commands\ServeCommand;

/**
 * Minimal console application wiring CLI commands.
 */
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
     * Register a command handler.
     *
     * @param string $name
     * @param callable(array<string,int|string|bool|null>):int $handler
     * @return void
     */
    public function register(string $name, callable $handler): void
    {
        $this->commands[$name] = $handler;
    }

    /**
     * Execute the application with the given argv.
     *
     * @param list<string> $argv
     * @return int Exit code
     */
    public function run(array $argv): int
    {
        array_shift($argv); // script path
        $commandName = $argv[0] ?? 'help';
        $options = $this->parseOptions(array_slice($argv, 1));
        if (!isset($this->commands[$commandName])) {
            $this->printHelp();
            return 1;
        }
        return (int) call_user_func($this->commands[$commandName], $options);
    }

    /**
     * Parse CLI options in the form --key=value into an associative array.
     *
     * @param list<string> $args
     * @return array<string,int|string|bool|null>
     */
    private function parseOptions(array $args): array
    {
        $options = [
            'host' => '127.0.0.1',
            'port' => 9966,
            'events' => null,
            'quiet' => false,
        ];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--host=')) {
                $options['host'] = substr($arg, 7);
            } elseif (str_starts_with($arg, '--port=')) {
                $options['port'] = (int) substr($arg, 7);
            } elseif (str_starts_with($arg, '--events=')) {
                $options['events'] = substr($arg, 9);
            } elseif ($arg === '--quiet') {
                $options['quiet'] = true;
            } elseif (str_starts_with($arg, '--quiet=')) {
                $value = strtolower((string) substr($arg, 8));
                $options['quiet'] = in_array($value, ['1', 'true', 'yes', 'on'], true);
            }
        }
        return $options;
    }

    /**
     * Print CLI help.
     *
     * @return void
     */
    private function printHelp(): void
    {
        echo "Cacheer Monitor CLI\n\n";
        echo "Commands:\n";
        echo "  serve           Start the local dashboard server\n\n";
        echo "Options:\n";
        echo "  --host=127.0.0.1  Bind address (default 127.0.0.1)\n";
        echo "  --port=9966       Port (default 9966)\n";
        echo "  --events=/path    JSONL events file path (default system temp or .env)\n";
        echo "  --quiet           Suppress server startup logs\n\n";
    }
}
