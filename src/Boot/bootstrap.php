<?php

declare(strict_types=1);

/**
 * Auto-registers the CacheerMonitorListener when cacheer-monitor is installed.
 *
 * Loaded by Composer's autoloader (autoload.files), so installing the package is
 * the only step required — no code changes. It registers a listener on
 * CacheerPHP v6's global telemetry tap; from then on every cache reports to the
 * dashboard, however it was built (named constructors, Cacheer::build(), a plain
 * `new Cacheer($store)`, tiered, or resilient).
 *
 * This runs on every request, so it must never throw and never emit output. That
 * makes silence the only safe failure mode, which is why every outcome is
 * recorded on {@see Bridge} — run `vendor/bin/cacheer-monitor doctor` to see what
 * happened and why.
 *
 * Value previews are opt-in: set CACHEER_MONITOR_CAPTURE_VALUES=true.
 * To wire the listener yourself instead, set CACHEER_MONITOR_AUTO_REGISTER=false
 * and register your own:
 *
 *   Telemetry::listen(
 *       (new CacheerMonitorListener(new JsonlReporter('/custom/events.jsonl')))->dispatch(...)
 *   );
 */

use Cacheer\Monitor\Boot\Bridge;
use Cacheer\Monitor\CacheerMonitorListener;
use Cacheer\Monitor\Reporter\JsonlReporter;
use Cacheer\Monitor\Support\Env;
use Silviooosilva\CacheerPhp\Observability\Telemetry;

(static function (): void {
    if (defined('CACHEER_MONITOR_BOOTSTRAPPED')) {
        Bridge::record(Bridge::ALREADY_BOOTED);

        return;
    }

    // The installed CacheerPHP predates the v6 telemetry tap (v4/v5), so there
    // is nothing to hook. Record it rather than vanishing — this is the failure
    // that looks exactly like "the monitor is broken".
    if (!Bridge::cacheerSupportsTelemetry()) {
        Bridge::record(Bridge::UNSUPPORTED_CACHEER);

        return;
    }

    if (!Env::getBool('CACHEER_MONITOR_AUTO_REGISTER', true)) {
        Bridge::record(Bridge::DISABLED);

        return;
    }

    define('CACHEER_MONITOR_BOOTSTRAPPED', true);

    if (Env::getBool('CACHEER_MONITOR_CAPTURE_VALUES')) {
        Telemetry::captureValues(true);
    }

    $reporter = new JsonlReporter();
    $listener = new CacheerMonitorListener($reporter);
    Telemetry::listen($listener->dispatch(...));

    Bridge::record(Bridge::ACTIVE, 'Events file: ' . $reporter->filePath());
})();
