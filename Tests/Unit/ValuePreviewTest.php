<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Tests\Unit;

use Cacheer\Monitor\Support\ValuePreview;
use PHPUnit\Framework\TestCase;

class ValuePreviewTest extends TestCase
{
    public function test_sensitive_fields_are_redacted_in_preview(): void
    {
        $preview = ValuePreview::build([
            'user' => 'silvio',
            'token' => 'secret-token',
            'nested' => [
                'password' => '123456',
                'profile' => ['authorization' => 'Bearer abc'],
            ],
        ]);

        $this->assertStringContainsString('"token":"[redacted]"', $preview);
        $this->assertStringContainsString('"password":"[redacted]"', $preview);
        $this->assertStringContainsString('"authorization":"[redacted]"', $preview);
        $this->assertStringNotContainsString('secret-token', $preview);
        $this->assertStringNotContainsString('123456', $preview);
    }
}
