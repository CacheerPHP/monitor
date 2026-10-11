<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Tests\Unit;

use Cacheer\Monitor\Support\Env;
use Cacheer\Monitor\Support\ValueTelemetry;
use PHPUnit\Framework\TestCase;

class ValueTelemetryTest extends TestCase
{
    private string|false $originalCaptureValues;

    protected function setUp(): void
    {
        $this->originalCaptureValues = getenv('CACHEER_MONITOR_CAPTURE_VALUES');
    }

    protected function tearDown(): void
    {
        if ($this->originalCaptureValues === false) {
            putenv('CACHEER_MONITOR_CAPTURE_VALUES');
        } else {
            putenv('CACHEER_MONITOR_CAPTURE_VALUES=' . $this->originalCaptureValues);
        }

        Env::reload();
    }

    public function test_describe_adds_metadata_without_preview_when_capture_is_disabled(): void
    {
        putenv('CACHEER_MONITOR_CAPTURE_VALUES=false');
        Env::reload();

        $telemetry = new ValueTelemetry();
        $metadata = $telemetry->describe(['name' => 'Cacheer']);

        $this->assertSame('array', $metadata['value_type']);
        $this->assertArrayHasKey('size_bytes', $metadata);
        $this->assertArrayNotHasKey('value_preview', $metadata);
    }

    public function test_describe_adds_preview_when_capture_is_enabled(): void
    {
        putenv('CACHEER_MONITOR_CAPTURE_VALUES=true');
        Env::reload();

        $telemetry = new ValueTelemetry();
        $metadata = $telemetry->describe(['name' => 'Cacheer']);

        $this->assertSame('array', $metadata['value_type']);
        $this->assertArrayHasKey('size_bytes', $metadata);
        $this->assertStringContainsString('"name":"Cacheer"', $metadata['value_preview']);
    }
}
