<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Tests\Unit;

use Cacheer\Monitor\Support\Path;
use PHPUnit\Framework\TestCase;

class PathTest extends TestCase
{
    public function test_resolve_absolute_path_returned_as_is(): void
    {
        $this->assertSame('/var/log/events.jsonl', Path::resolve('/var/log/events.jsonl'));
    }

    public function test_resolve_relative_path_joined_to_base(): void
    {
        $result = Path::resolve('logs/events.jsonl', '/var/www');

        $this->assertSame('/var/www/logs/events.jsonl', $result);
    }

    public function test_resolve_trims_slashes_correctly(): void
    {
        $result = Path::resolve('/leading/path.jsonl', '/base/');

        // Absolute path should be returned as-is
        $this->assertSame('/leading/path.jsonl', $result);
    }

    public function test_resolve_relative_with_leading_slash_on_path(): void
    {
        $result = Path::resolve('relative/file.txt', '/base');

        $this->assertSame('/base/relative/file.txt', $result);
    }
}
