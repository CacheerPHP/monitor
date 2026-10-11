<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Support;

/** Archive naming shared by the reporter and dashboard actions. */
final class EventLogFiles
{
    /** Caller must hold the events file's .lock before archiving. */
    public static function archive(string $filePath, string $suffix): bool
    {
        $prefix = $filePath . '.' . date('Ymd_His');
        $archive = $prefix . '.' . $suffix;
        $sequence = 0;

        while (file_exists($archive) || is_link($archive)) {
            $archive = $prefix . '.' . ++$sequence . '.' . $suffix;
        }

        return @rename($filePath, $archive);
    }

    public static function isArchive(string $filePath, string $filename): bool
    {
        $basename = preg_quote(basename($filePath), '/');

        return preg_match('/^' . $basename . '\.\d{8}_\d{6}(?:\.\d+)?\.(?:rotated|bak)$/D', $filename) === 1;
    }
}
