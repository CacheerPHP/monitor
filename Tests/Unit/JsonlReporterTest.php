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

        $lines = array_filter(explode("\n", file_get_contents($this->tempFile)));
        $this->assertCount(3, $lines);
    }

    public function test_rotation_triggers_at_max_bytes(): void
    {
        // Set tiny max (100 bytes) to force rotation
        $reporter = new JsonlReporter($this->tempFile, 100, 'test-id');

        // Write enough data to trigger rotation
        for ($i = 0; $i < 5; $i++) {
            $reporter->event('hit', ['key' => 'key-' . $i, 'data' => str_repeat('x', 50)]);
        }

        // Should have rotated — check for .rotated files
        $rotatedFiles = glob($this->tempFile . '.*.rotated');
        $this->assertCount(4, $rotatedFiles);
        $keys = [];
        foreach (array_merge($rotatedFiles, [$this->tempFile]) as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $keys[] = json_decode($line, true)['payload']['key'];
            }
        }
        sort($keys);
        $this->assertSame(['key-0', 'key-1', 'key-2', 'key-3', 'key-4'], $keys);
    }

    public function test_instance_id_auto_generated(): void
    {
        $reporter = new JsonlReporter($this->tempFile);

        $reporter->event('hit', []);

        $decoded = json_decode(trim(file_get_contents($this->tempFile)), true);
        $this->assertNotEmpty($decoded['instance']);
        $this->assertSame(8, strlen($decoded['instance'])); // bin2hex(4 bytes) = 8 chars
    }
}
