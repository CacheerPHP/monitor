<?php

declare(strict_types=1);

/**
 * Auto-registers the CacheerMonitorListener when cacheer-monitor is installed.
 *
 * Loaded automatically by Composer's autoloader (autoload.files), so installing
 * the package is the only step required — no code changes needed. It registers a
 * listener on CacheerPHP v6's global telemetry tap; from then on every cache
 * built through the named constructors (Cacheer::file(), inMemory(), redis(),
 * database(), and Cacheer::build()) reports to the dashboard.
 *
 * Value previews are opt-in: set CACHEER_MONITOR_CAPTURE_VALUES=true to include
 * them. To use a custom events file, register your own listener instead:
 *
 *   Telemetry::reset();
 *   Telemetry::listen(
 *       (new CacheerMonitorListener(new JsonlReporter('/custom/events.jsonl')))->dispatch(...)
 *   );
 */

use Cacheer\Monitor\CacheerMonitorListener;
use Cacheer\Monitor\Reporter\JsonlReporter;
use Cacheer\Monitor\Support\Env;
use Silviooosilva\CacheerPhp\Observability\Telemetry;

if (!defined('CACHEER_MONITOR_BOOTSTRAPPED') && class_exists(Telemetry::class)) {
    define('CACHEER_MONITOR_BOOTSTRAPPED', true);

    if (Env::getBool('CACHEER_MONITOR_CAPTURE_VALUES')) {
        Telemetry::captureValues(true);
    }

    $listener = new CacheerMonitorListener(new JsonlReporter());
    Telemetry::listen($listener->dispatch(...));
}
