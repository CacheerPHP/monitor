<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Tests\Unit;

use Cacheer\Monitor\Boot\Bridge;
use Cacheer\Monitor\CacheerMonitorListener;
use Cacheer\Monitor\Reporter\JsonlReporter;
use Cacheer\Monitor\Reporter\MetricsReporterInterface;
use Silviooosilva\CacheerPhp\Cacheer;
use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Observability\CacheEvent;
use Silviooosilva\CacheerPhp\Observability\Telemetry;

/**
 * The autoload bridge runs on every request, so it can never warn or throw.
 * Silence is its only safe failure mode — which is precisely what made a stale
 * CacheerPHP install look like "the monitor is broken". Every outcome must
 * therefore be recorded and explainable.
 */
class BridgeTest extends TestCase
{
    /** The status the real autoload bridge recorded, captured before any test mutates it. */
    private static string $bootStatus;

    public static function setUpBeforeClass(): void
    {
        self::$bootStatus = Bridge::status();
    }

    protected function tearDown(): void
    {
        // Restore, don't wipe: the boot status is process-global and set once at
        // autoload, so clearing it would break any later assertion about it.
        Bridge::record(self::$bootStatus);
    }

    public function test_bootstrap_ran_and_registered_a_listener(): void
    {
        // The real bootstrap has already run via composer's autoload.files.
        // ACTIVE is only recorded after Telemetry::listen() succeeded, so this
        // asserts the registration itself. The live listener list is not checked
        // here on purpose: it is process-global, and sibling tests legitimately
        // call Telemetry::reset().
        $this->assertSame(
            Bridge::ACTIVE,
            self::$bootStatus,
            'The autoload bridge must be active at autoload time.',
        );
    }

    public function test_a_registered_listener_actually_carries_events_through(): void
    {
        Telemetry::reset();

        $file = sys_get_temp_dir() . '/bridge-test-' . bin2hex(random_bytes(4)) . '.jsonl';
        $listener = new CacheerMonitorListener(new JsonlReporter($file, null));
        Telemetry::listen($listener->dispatch(...));

        // Built through a named constructor, exactly as a consuming app would.
        $cache = Cacheer::inMemory();
        $cache->set('user:1', 'ada');
        $cache->get('user:1');
        $cache->get('absent');

        $this->assertFileExists($file, 'Events must reach the reporter.');

        $types = array_map(
            static fn (string $line): string => json_decode($line, true)['type'],
            file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        );
        $this->assertSame(['put', 'hit', 'miss'], $types);

        @unlink($file);
        @unlink($file . '.lock');
        Telemetry::reset();
    }

    public function test_installed_cacheer_exposes_the_telemetry_tap(): void
    {
        $this->assertTrue(
            Bridge::cacheerSupportsTelemetry(),
            'The monitor requires CacheerPHP 6; a v4/v5 install has no Telemetry to hook.',
        );
    }

    public function test_every_status_explains_itself(): void
    {
        $statuses = [
            Bridge::ACTIVE,
            Bridge::DISABLED,
            Bridge::UNSUPPORTED_CACHEER,
            Bridge::ALREADY_BOOTED,
            Bridge::NOT_BOOTED,
        ];

        foreach ($statuses as $status) {
            Bridge::record($status);
            $this->assertSame($status, Bridge::status());
            $this->assertNotSame('', Bridge::explain(), "{$status} must explain itself");
        }
    }

    public function test_unsupported_cacheer_names_the_cause_and_the_fix(): void
    {
        Bridge::record(Bridge::UNSUPPORTED_CACHEER);
        $explanation = Bridge::explain();

        // This is the exact failure users hit: 6.0 is still a pre-release, so a
        // plain "^6.0" silently resolves to v4/v5 and the tap does not exist.
        $this->assertStringContainsString('Telemetry', $explanation);
        $this->assertStringContainsString('CacheerPHP 6', $explanation);
        $this->assertStringContainsString('^6.0@RC', $explanation);
    }

    public function test_never_booted_is_the_default_and_points_at_composer(): void
    {
        Bridge::reset();

        $this->assertSame(Bridge::NOT_BOOTED, Bridge::status());
        $this->assertFalse(Bridge::isActive());
        $this->assertStringContainsString('composer dump-autoload', Bridge::explain());
    }

    public function test_boot_status_is_stable_across_tests(): void
    {
        // Guards the tearDown contract above: a sibling test must not be able to
        // leave the recorded boot status clobbered.
        $this->assertSame(self::$bootStatus, Bridge::status());
    }

    public function test_error_messages_are_reported_without_credentials(): void
    {
        $reporter = new class () implements MetricsReporterInterface {
            /** @var array<string, mixed> */
            public array $payload = [];

            public function event(string $type, array $payload = []): void
            {
                $this->payload = $payload;
            }
        };

        $message = 'Connection refused [tcp://admin:s3cret@redis:6379]; DSN mysql:host=db;password=hunter2 auth: tok3n';
        (new CacheerMonitorListener($reporter))->dispatch(
            CacheEvent::failed('RedisStore', 'k', 1.0, new \RuntimeException($message)),
        );

        $error = $reporter->payload['error'];
        $this->assertStringNotContainsString('s3cret', $error);
        $this->assertStringNotContainsString('hunter2', $error);
        $this->assertStringNotContainsString('tok3n', $error);
        $this->assertStringContainsString('Connection refused', $error, 'The useful part of the message survives.');
        $this->assertStringContainsString('redis:6379', $error);
    }
}
