<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Tests\Unit;

use Cacheer\Monitor\Reporter\JsonlReporter;
use PHPUnit\Framework\TestCase;

class JsonlReporterTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = sys_get_temp_dir() . '/cacheer-reporter-test-' . bin2hex(random_bytes(4)) . '.jsonl';
    }

    protected function tearDown(): void
    {
        if (is_file($this->tempFile)) {
            @unlink($this->tempFile);
        }
        foreach (glob($this->tempFile . '.*') as $file) {
            @unlink($file);
        }
    }

    public function test_event_appends_jsonl_line(): void
    {
        $reporter = new JsonlReporter($this->tempFile, null, 'test-id');

        $reporter->event('hit', ['key' => 'test-key']);
        $reporter->flush();

        $content = file_get_contents($this->tempFile);
        $this->assertNotEmpty($content);

        $decoded = json_decode(trim($content), true);
        $this->assertSame('hit', $decoded['type']);
        $this->assertSame('test-id', $decoded['instance']);
        $this->assertSame('test-key', $decoded['payload']['key']);
        $this->assertIsFloat($decoded['ts']);
    }

    public function test_event_appends_multiple_lines(): void
    {
        $reporter = new JsonlReporter($this->tempFile, null, 'test-id');

        $reporter->event('hit', ['key' => 'a']);
        $reporter->event('miss', ['key' => 'b']);
        $reporter->event('put', ['key' => 'c']);
        $reporter->flush();

        $this->assertSame(['a', 'b', 'c'], $this->keys($this->tempFile));
    }

    public function test_rotation_triggers_at_max_bytes(): void
    {
        // Set tiny max (100 bytes) to force rotation
        $reporter = new JsonlReporter($this->tempFile, 100, 'test-id');

        // Write enough data to trigger rotation
        for ($i = 0; $i < 5; $i++) {
            $reporter->event('hit', ['key' => 'key-' . $i, 'data' => str_repeat('x', 50)]);
        }
        $reporter->flush();

        // One buffered batch still rotates between lines, as single writes did.
        $rotatedFiles = glob($this->tempFile . '.*.rotated');
        $this->assertCount(4, $rotatedFiles);
        $keys = [];
        foreach (array_merge($rotatedFiles, [$this->tempFile]) as $file) {
            $keys = array_merge($keys, $this->keys($file));
        }
        sort($keys);
        $this->assertSame(['key-0', 'key-1', 'key-2', 'key-3', 'key-4'], $keys);
    }

    public function test_instance_id_auto_generated(): void
    {
        $reporter = new JsonlReporter($this->tempFile);

        $reporter->event('hit', []);
        $reporter->flush();

        $decoded = json_decode(trim(file_get_contents($this->tempFile)), true);
        $this->assertNotEmpty($decoded['instance']);
        $this->assertSame(8, strlen($decoded['instance'])); // bin2hex(4 bytes) = 8 chars
    }

    public function test_events_are_buffered_until_flushed(): void
    {
        $reporter = new JsonlReporter($this->tempFile, null, 'test-id');

        $reporter->event('hit', ['key' => 'a']);
        $reporter->event('miss', ['key' => 'b']);

        $this->assertFileDoesNotExist($this->tempFile);

        $reporter->flush();
        $reporter->flush();

        $this->assertSame(['a', 'b'], $this->keys($this->tempFile));
    }

    public function test_a_full_buffer_is_written_without_a_flush(): void
    {
        $reporter = new JsonlReporter($this->tempFile, null, 'test-id', maxBufferedEvents: 3);

        foreach (['a', 'b', 'c', 'd'] as $key) {
            $reporter->event('hit', ['key' => $key]);
        }

        $this->assertSame(['a', 'b', 'c'], $this->keys($this->tempFile));
    }

    public function test_buffered_events_are_written_once_the_interval_has_passed(): void
    {
        $reporter = new JsonlReporter($this->tempFile, null, 'test-id', maxBufferedSeconds: 0.05);

        $reporter->event('hit', ['key' => 'a']);
        $this->assertFileDoesNotExist($this->tempFile);

        usleep(60_000);
        $reporter->event('hit', ['key' => 'b']);

        $this->assertSame(['a', 'b'], $this->keys($this->tempFile));
    }

    public function test_destroying_the_reporter_writes_its_buffer(): void
    {
        $reporter = new JsonlReporter($this->tempFile, null, 'test-id');
        $reporter->event('hit', ['key' => 'a']);

        unset($reporter);

        $this->assertSame(['a'], $this->keys($this->tempFile));
    }

    public function test_buffered_events_are_written_when_the_process_dies_of_a_fatal_error(): void
    {
        $output = $this->runPhp(<<<'PHP'
            $reporter = new Cacheer\Monitor\Reporter\JsonlReporter($argv[1], null, 'fatal');
            $reporter->event('hit', ['key' => 'before-the-crash']);
            // A true fatal error: PHP skips destructors after it, but still runs shutdown functions.
            ini_set('memory_limit', '16M');
            $hog = str_repeat('x', 64 * 1024 * 1024);
            PHP);

        $this->assertStringContainsString('Allowed memory size', $output);
        $this->assertSame(['before-the-crash'], $this->keys($this->tempFile));
    }

    public function test_concurrent_processes_write_whole_lines_and_lose_nothing(): void
    {
        $script = <<<'PHP'
            $reporter = new Cacheer\Monitor\Reporter\JsonlReporter($argv[1], null, $argv[2]);
            for ($i = 0; $i < 2000; $i++) {
                $reporter->event('hit', ['key' => $argv[2] . ':' . $i, 'data' => str_repeat('x', 200)]);
            }
            PHP;
        $processes = [];
        foreach (['p1', 'p2', 'p3', 'p4'] as $name) {
            $processes[$name] = $this->startPhp($script, $name);
        }
        foreach ($processes as $process) {
            proc_close($process);
        }

        $keys = $this->keys($this->tempFile);
        $this->assertCount(8000, $keys);
        $this->assertCount(8000, array_unique($keys));
        foreach (['p1', 'p2', 'p3', 'p4'] as $name) {
            $own = array_values(array_filter($keys, static fn (string $key): bool => str_starts_with($key, $name . ':')));
            $this->assertSame($name . ':0', $own[0]);
            $this->assertSame($name . ':1999', $own[1999], 'Each process keeps its own order.');
        }
    }

    /**
     * @return list<string>
     */
    private function keys(string $file): array
    {
        $this->assertFileExists($file);
        $keys = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded, 'Every line must be a whole JSON record.');
            $keys[] = $decoded['payload']['key'];
        }

        return $keys;
    }

    private function runPhp(string $code): string
    {
        $process = $this->startPhp($code, '', $pipes);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($process);

        return $output;
    }

    /**
     * @param array<int, resource>|null $pipes
     * @return resource
     */
    private function startPhp(string $code, string $argument, ?array &$pipes = null)
    {
        $script = $this->tempFile . '.' . bin2hex(random_bytes(3)) . '.script.php';
        file_put_contents($script, "<?php\nrequire " . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n" . $code . "\n");

        $process = proc_open(
            [PHP_BINARY, $script, $this->tempFile, $argument],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['CACHEER_MONITOR_AUTO_REGISTER' => 'false'] + getenv(),
        );
        $this->assertIsResource($process);

        return $process;
    }
}
