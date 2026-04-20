<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Http;

use Cacheer\Monitor\Application\MonitorContext;


final class EventStreamResponder
{   
    /**
     * Initializes the EventStreamResponder with the given MonitorContext.
     *
     * @param MonitorContext $context
     */
    public function __construct(private readonly MonitorContext $context)
    {
    }

    /**
     * Handles the event stream response, sending new events to the client as they arrive.
     *
     * @return void
     */
    public function stream(): void
    {
        $timeout = $this->context->streamTimeout();
        $maxChunkBytes = 1024 * 1024;

        @ignore_user_abort(true);
        @set_time_limit(0);
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');

        $file = $this->context->eventsFile();
        $lastSize = is_file($file) ? (int) filesize($file) : 0;
        $start = time();

        while (!connection_aborted() && (time() - $start) < $timeout) {
            clearstatcache(true, $file);
            $currentSize = is_file($file) ? (int) filesize($file) : 0;

            if ($currentSize > $lastSize) {
                $handle = @fopen($file, 'rb');

                if ($handle) {
                    @fseek($handle, $lastSize);
                    $chunk = stream_get_contents($handle, $maxChunkBytes) ?: '';
                    @fclose($handle);

                    $lines = preg_split("/[\r\n]+/", $chunk, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    foreach ($lines as $line) {
                        echo 'data: ' . $line . "\n\n";
                    }

                    @ob_flush();
                    @flush();
                }

                $lastSize = $currentSize;
            } else {
                echo "event: ping\n";
                echo 'data: ' . json_encode(['ts' => microtime(true)]) . "\n\n";
                @ob_flush();
                @flush();
            }

            usleep(500000); // Sleep for 500ms to avoid busy waiting
        }
    }
}
