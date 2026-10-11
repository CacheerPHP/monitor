<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Tests\Unit;

use Cacheer\Monitor\Support\Env;
use PHPUnit\Framework\TestCase;

class EnvTest extends TestCase
{
    private string|false $originalAutoload;
    private string|false $originalCaptureValues;

    protected function setUp(): void
    {
        $this->originalAutoload = getenv('CACHEER_AUTOLOAD');
        $this->originalCaptureValues = getenv('CACHEER_MONITOR_CAPTURE_VALUES');
    }

    protected function tearDown(): void
    {
        if ($this->originalAutoload === false) {
            putenv('CACHEER_AUTOLOAD');
        } else {
            putenv('CACHEER_AUTOLOAD=' . $this->originalAutoload);
        }

        if ($this->originalCaptureValues === false) {
            putenv('CACHEER_MONITOR_CAPTURE_VALUES');
        } else {
            putenv('CACHEER_MONITOR_CAPTURE_VALUES=' . $this->originalCaptureValues);
        }
    }

    public function test_root_uses_cacheer_autoload_project_root_when_available(): void
    {
        putenv('CACHEER_AUTOLOAD=/tmp/example-app/vendor/autoload.php');

        $this->assertSame('/tmp/example-app', Env::root());
    }

    public function test_get_bool_accepts_common_truthy_and_falsey_strings(): void
    {
        putenv('CACHEER_MONITOR_CAPTURE_VALUES=on');
        $this->assertTrue(Env::getBool('CACHEER_MONITOR_CAPTURE_VALUES'));

        putenv('CACHEER_MONITOR_CAPTURE_VALUES=no');
        $this->assertFalse(Env::getBool('CACHEER_MONITOR_CAPTURE_VALUES', true));
    }
}
