<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Console\Commands;

use Cacheer\Monitor\Boot\Bridge;
use Cacheer\Monitor\Reporter\JsonlReporter;
use Cacheer\Monitor\Support\Env;
use Silviooosilva\CacheerPhp\Observability\Telemetry;

/**
 * Reports whether the autoload bridge is live, and why not when it isn't.
 *
 * The bridge cannot warn at runtime — it runs at autoload in every request, so
 * it must stay silent. This command is where that silence gets explained.
 */
final class DoctorCommand
{
    /**
     * @param array<string,int|string|bool|null> $args
     * @return int Exit code: 0 healthy, 1 needs attention
     */
    public function run(array $args): int
    {
        $ok = true;

        fwrite(STDOUT, "Cacheer Monitor — doctor\n\n");

        // 1. Is a CacheerPHP with the v6 telemetry tap installed?
        $supported = Bridge::cacheerSupportsTelemetry();
        $ok = $this->line('CacheerPHP telemetry tap', $supported, $supported
            ? 'Observability\\Telemetry found'
            : 'not found — the monitor needs CacheerPHP 6') && $ok;

        // 2. Did Composer actually run the bridge?
        $active = Bridge::isActive();
        $ok = $this->line('Autoload bridge', $active, Bridge::status()) && $ok;

        // 3. Is a listener really registered on the tap?
        $listening = $supported && Telemetry::hasListeners();
        $ok = $this->line('Listener registered', $listening, $listening
            ? 'caches will report'
            : 'nothing is listening — caches report nowhere') && $ok;

        // 4. Where do events go, and can we write there?
        $path = (new JsonlReporter())->filePath();
        $dir = dirname($path);
        $writable = is_dir($dir) && is_writable($dir);
        $ok = $this->line('Events file', $writable, $path
            . ($writable ? '' : '  (directory not writable)')) && $ok;

        if ($writable && !is_file($path)) {
            fwrite(STDOUT, "    note: file does not exist yet — it is created on the first cache operation\n");
        }

        // 5. Value capture is off by default; say so, since a missing preview
        //    otherwise looks like a bug.
        $capture = Env::getBool('CACHEER_MONITOR_CAPTURE_VALUES');
        fwrite(STDOUT, sprintf(
            "  %s Value previews        %s\n",
            $capture ? '[on] ' : '[off]',
            $capture ? 'CACHEER_MONITOR_CAPTURE_VALUES=true' : 'set CACHEER_MONITOR_CAPTURE_VALUES=true to enable',
        ));

        fwrite(STDOUT, "\n" . Bridge::explain() . "\n");

        if (!$ok) {
            fwrite(STDOUT, "\nOne or more checks failed — the dashboard will show no data until they pass.\n");
        }

        return $ok ? 0 : 1;
    }

    private function line(string $label, bool $ok, string $detail): bool
    {
        fwrite(STDOUT, sprintf("  %s %-21s %s\n", $ok ? '[ok] ' : '[FAIL]', $label, $detail));

        return $ok;
    }
}
