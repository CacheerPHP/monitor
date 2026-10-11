<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Tests\Unit;

use Cacheer\Monitor\Domain\EventStore;
use PHPUnit\Framework\TestCase;

class EventStoreTest extends TestCase
{
    private string $tempFile;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/cacheer-monitor-test-' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0700);
        $this->tempFile = $this->tempDir . '/events.jsonl';
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    private function removeDir(string $dir): void
    {
        foreach (scandir($dir) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            is_dir($path) && !is_link($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function test_read_all_returns_empty_for_missing_file(): void
    {
        $store = new EventStore('/nonexistent/path.jsonl');

        $this->assertSame([], $store->readAll());
    }

    public function test_read_all_parses_jsonl(): void
    {
        $events = [
            ['ts' => 1.0, 'type' => 'hit', 'payload' => ['key' => 'a']],
            ['ts' => 2.0, 'type' => 'miss', 'payload' => ['key' => 'b']],
        ];
        $content = implode("\n", array_map('json_encode', $events)) . "\n";
        file_put_contents($this->tempFile, $content);

        $store = new EventStore($this->tempFile);
        $result = $store->readAll();

        $this->assertCount(2, $result);
        $this->assertSame('hit', $result[0]['type']);
        $this->assertSame('miss', $result[1]['type']);
    }

    public function test_read_all_respects_limit(): void
    {
        $lines = '';
        for ($i = 0; $i < 10; $i++) {
            $lines .= json_encode(['ts' => (float) $i, 'type' => 'hit', 'payload' => []]) . "\n";
        }
        file_put_contents($this->tempFile, $lines);

        $store = new EventStore($this->tempFile);
        $result = $store->readAll(3);

        $this->assertCount(3, $result);
        // Should return the last 3 events
        $this->assertEquals(7, $result[0]['ts']);
    }

    public function test_read_all_limit_tails_large_file_across_chunks(): void
    {
        // Write a file larger than the backward reader's 64KB chunk so the
        // tail scan must stitch multiple chunks together. Each line is padded
        // so 5000 lines comfortably exceeds the chunk boundary.
        $handle = fopen($this->tempFile, 'wb');
        for ($i = 0; $i < 5000; $i++) {
            fwrite($handle, json_encode([
                'ts'      => (float) $i,
                'type'    => 'hit',
                'payload' => ['key' => 'k' . $i, 'pad' => str_repeat('x', 40)],
            ]) . "\n");
        }
        fclose($handle);

        $store  = new EventStore($this->tempFile);
        $result = $store->readAll(5);

        $this->assertCount(5, $result);
        // Oldest-first within the tail: last 5 of 0..4999 → 4995..4999
        $this->assertEquals(4995, $result[0]['ts']);
        $this->assertEquals(4999, $result[4]['ts']);
        $this->assertSame('k4999', $result[4]['payload']['key']);
    }

    public function test_read_all_limit_larger_than_file_returns_all(): void
    {
        $lines = '';
        for ($i = 0; $i < 4; $i++) {
            $lines .= json_encode(['ts' => (float) $i, 'type' => 'hit', 'payload' => []]) . "\n";
        }
        file_put_contents($this->tempFile, $lines);

        $store  = new EventStore($this->tempFile);
        $result = $store->readAll(100);

        $this->assertCount(4, $result);
        $this->assertEquals(0, $result[0]['ts']);
        $this->assertEquals(3, $result[3]['ts']);
    }

    public function test_read_all_filters_by_namespace(): void
    {
        $events = [
            ['ts' => 1.0, 'type' => 'hit', 'payload' => ['namespace' => 'api']],
            ['ts' => 2.0, 'type' => 'hit', 'payload' => ['namespace' => 'web']],
            ['ts' => 3.0, 'type' => 'hit', 'payload' => ['namespace' => 'api']],
        ];
        $content = implode("\n", array_map('json_encode', $events)) . "\n";
        file_put_contents($this->tempFile, $content);

        $store = new EventStore($this->tempFile);
        $result = $store->readAll(0, 'api');

        $this->assertCount(2, $result);
    }

    public function test_read_all_default_namespace_filter(): void
    {
        $events = [
            ['ts' => 1.0, 'type' => 'hit', 'payload' => ['namespace' => '']],
            ['ts' => 2.0, 'type' => 'hit', 'payload' => ['namespace' => 'api']],
        ];
        $content = implode("\n", array_map('json_encode', $events)) . "\n";
        file_put_contents($this->tempFile, $content);

        $store = new EventStore($this->tempFile);

        // Filtering by '(default)' should match events with empty namespace
        $result = $store->readAll(0, '(default)');
        $this->assertCount(1, $result);
        $this->assertSame('', $result[0]['payload']['namespace']);
    }

    public function test_read_all_skips_invalid_json(): void
    {
        $content = json_encode(['ts' => 1.0, 'type' => 'hit', 'payload' => []]) . "\n";
        $content .= "not valid json\n";
        $content .= json_encode(['ts' => 2.0, 'type' => 'miss', 'payload' => []]) . "\n";
        file_put_contents($this->tempFile, $content);

        $store = new EventStore($this->tempFile);
        $result = $store->readAll();

        $this->assertCount(2, $result);
    }

    public function test_clear_rotates_file(): void
    {
        file_put_contents($this->tempFile, json_encode(['ts' => 1.0]) . "\n");

        $store = new EventStore($this->tempFile);
        $result = $store->clear();

        $this->assertTrue($result);
        // Original file should be empty (recreated)
        $this->assertSame('', file_get_contents($this->tempFile));
    }

    public function test_path_returns_file_path(): void
    {
        $store = new EventStore($this->tempFile);

        $this->assertSame($this->tempFile, $store->path());
    }

    public function test_cleanup_only_deletes_aged_archives_for_its_own_events_file(): void
    {
        $old = time() - 10 * 86400;
        $archives = ['events.jsonl.20260101_120000.rotated', 'events.jsonl.20260101_120000.bak', 'events.jsonl.20260101_120000.1.rotated'];
        $preserved = ['database.bak', 'other.jsonl.20260101_120000.rotated', 'events.jsonl.backup.bak', 'events.jsonl', 'events.jsonl.lock'];

        foreach (array_merge($archives, $preserved) as $name) {
            file_put_contents($this->tempDir . '/' . $name, 'keep');
            touch($this->tempDir . '/' . $name, $old);
        }
        $recent = $this->tempFile . '.20260101_120000.2.rotated';
        file_put_contents($recent, 'recent');
        $directory = $this->tempFile . '.20260101_120000.3.rotated';
        mkdir($directory);
        touch($directory, $old);
        $link = $this->tempFile . '.20260101_120000.4.bak';
        symlink($this->tempDir . '/database.bak', $link);

        $this->assertSame(3, (new EventStore($this->tempFile))->cleanRotated(7));
        foreach ($archives as $name) {
            $this->assertFileDoesNotExist($this->tempDir . '/' . $name);
        }
        foreach ($preserved as $name) {
            $this->assertFileExists($this->tempDir . '/' . $name);
        }
        $this->assertFileExists($recent);
        $this->assertDirectoryExists($directory);
        $this->assertTrue(is_link($link));
    }

    public function test_repeated_clear_preserves_every_archived_log(): void
    {
        $store = new EventStore($this->tempFile);
        for ($i = 0; $i < 10; $i++) {
            file_put_contents($this->tempFile, json_encode(['ts' => $i]) . "\n");
            $this->assertTrue($store->clear());
        }

        $archives = glob($this->tempFile . '.*.bak');
        $this->assertCount(10, $archives);
        $timestamps = array_map(static fn(string $file): int => json_decode(file_get_contents($file), true)['ts'], $archives);
        sort($timestamps);
        $this->assertSame(range(0, 9), $timestamps);
        $this->assertSame('', file_get_contents($this->tempFile));
    }

    public function test_clear_preserves_events_when_the_shared_lock_cannot_be_opened(): void
    {
        file_put_contents($this->tempFile, 'important events');
        mkdir($this->tempFile . '.lock');

        $this->assertFalse((new EventStore($this->tempFile))->clear());
        $this->assertSame('important events', file_get_contents($this->tempFile));
    }
}
