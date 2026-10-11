<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Tests\Unit;

use Cacheer\Monitor\Console\Commands\ServeCommand;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ServeCommandTest extends TestCase
{
    private string|false $originalAutoload;

    private string|false $originalWorkers;

    protected function setUp(): void
    {
        $this->originalAutoload = getenv('CACHEER_AUTOLOAD');
        $this->originalWorkers = getenv('CACHEER_MONITOR_WORKERS');
    }

    protected function tearDown(): void
    {
        $this->restoreEnv('CACHEER_AUTOLOAD', $this->originalAutoload);
        $this->restoreEnv('CACHEER_MONITOR_WORKERS', $this->originalWorkers);
    }

    private function restoreEnv(string $key, string|false $original): void
    {
        if ($original === false) {
            putenv($key);
            return;
        }

        putenv($key . '=' . $original);
    }

    private function determineWorkers(?string $opt): int
    {
        $command = new ServeCommand();
        $method = new ReflectionMethod($command, 'determineWorkers');
        $method->setAccessible(true);

        return $method->invoke($command, $opt);
    }

    public function test_relative_events_path_is_resolved_from_project_root(): void
    {
        putenv('CACHEER_AUTOLOAD=/tmp/example-app/vendor/autoload.php');

        $command = new ServeCommand();
        $resolvePath = new ReflectionMethod($command, 'resolvePath');
        $resolvePath->setAccessible(true);

        $resolvedPath = $resolvePath->invoke($command, 'Tests/cacheer-events.jsonl');

        $this->assertSame('/tmp/example-app/Tests/cacheer-events.jsonl', $resolvedPath);
    }

    public function test_workers_default_allows_concurrent_requests_during_a_stream(): void
    {
        putenv('CACHEER_MONITOR_WORKERS');

        $this->assertSame(4, $this->determineWorkers(null));
    }

    public function test_explicit_option_overrides_the_environment(): void
    {
        putenv('CACHEER_MONITOR_WORKERS=2');

        $this->assertSame(8, $this->determineWorkers('8'));
    }

    public function test_environment_is_used_when_no_option_is_given(): void
    {
        putenv('CACHEER_MONITOR_WORKERS=6');

        $this->assertSame(6, $this->determineWorkers(null));
    }

    public function test_worker_count_is_clamped_to_a_sane_range(): void
    {
        putenv('CACHEER_MONITOR_WORKERS');

        $this->assertSame(1, $this->determineWorkers('0'));
        $this->assertSame(1, $this->determineWorkers('-5'));
        $this->assertSame(32, $this->determineWorkers('999'));
    }
}
