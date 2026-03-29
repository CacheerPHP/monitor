<?php

declare(strict_types=1);

/**
 * Auto-registers the CacheerMonitorListener when cacheer-monitor is installed.
 *
 * This file is loaded automatically by Composer's autoloader (autoload.files),
 * so installing the package is the only step required — no code changes needed.
 *
 * To use a custom events file path, remove the auto-registered listener and
 * add your own before any cache calls:
 *
 *   Cacheer::removeListeners();
 *   Cacheer::addListener(new CacheerMonitorListener(
 *       new JsonlReporter('/custom/path/to/events.jsonl')
 *   ));
 */

use Cacheer\Monitor\CacheerMonitorListener;
use Cacheer\Monitor\Reporter\JsonlReporter;
use Silviooosilva\CacheerPhp\Cacheer;

if (class_exists(Cacheer::class)) {
    Cacheer::addListener(new CacheerMonitorListener(new JsonlReporter()));
}
