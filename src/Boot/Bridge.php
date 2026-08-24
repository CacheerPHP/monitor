<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Boot;

use Silviooosilva\CacheerPhp\Observability\Telemetry;

/**
 * Records what the autoload bridge did, so a monitor that reports nothing can be
 * diagnosed instead of guessed at.
 *
 * The bridge runs at autoload time in every request, so it must never throw and
 * must never emit output. That makes silence its only safe failure mode — and
 * silence is exactly what made a stale/incompatible CacheerPHP install look like
 * "the monitor is broken". This class keeps the reason, and `cacheer-monitor
 * doctor` prints it.
 */
final class Bridge
{
    /** Listener registered; caches report to the dashboard. */
    public const ACTIVE = 'active';

    /** Turned off explicitly via CACHEER_MONITOR_AUTO_REGISTER=false. */
    public const DISABLED = 'disabled';

    /** The installed CacheerPHP has no Telemetry tap — it predates v6. */
    public const UNSUPPORTED_CACHEER = 'unsupported-cacheer';

    /** Something already booted the bridge in this process. */
    public const ALREADY_BOOTED = 'already-booted';

    /** The bridge never ran: composer's autoload.files did not load it. */
    public const NOT_BOOTED = 'not-booted';

    private static ?string $status = null;

    private static ?string $detail = null;

    public static function record(string $status, ?string $detail = null): void
    {
        self::$status = $status;
        self::$detail = $detail;
    }

    public static function status(): string
    {
        return self::$status ?? self::NOT_BOOTED;
    }

    public static function isActive(): bool
    {
        return self::status() === self::ACTIVE;
    }

    /**
     * A one-line explanation of the current status, with the fix where there is
     * one to suggest.
     */
    public static function explain(): string
    {
        return match (self::status()) {
            self::ACTIVE => 'Bridge active — caches report to the monitor.'
                . (self::$detail !== null ? ' ' . self::$detail : ''),
            self::DISABLED => 'Bridge disabled by CACHEER_MONITOR_AUTO_REGISTER=false. '
                . 'Unset it, or register a listener yourself with Telemetry::listen().',
            self::UNSUPPORTED_CACHEER => 'The installed silviooosilva/cacheer-php has no '
                . 'Observability\\Telemetry, so there is nothing to hook. The monitor needs '
                . 'CacheerPHP 6. Run: composer why silviooosilva/cacheer-php — and if it '
                . 'resolved to v4/v5, note that 6.0 is not tagged yet, so the constraint '
                . 'must allow the dev branch ("^6.0@dev").',
            self::ALREADY_BOOTED => 'Bridge already booted earlier in this process.'
                . (self::$detail !== null ? ' ' . self::$detail : ''),
            default => 'Bridge never ran. Composer did not load src/Boot/bootstrap.php — '
                . 'check that autoload.files is present in the installed package and run '
                . 'composer dump-autoload.',
        };
    }

    /**
     * Whether the installed CacheerPHP exposes the v6 telemetry tap.
     */
    public static function cacheerSupportsTelemetry(): bool
    {
        return class_exists(Telemetry::class);
    }

    /**
     * Reset recorded state. For tests.
     */
    public static function reset(): void
    {
        self::$status = null;
        self::$detail = null;
    }
}
