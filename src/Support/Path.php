<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Support;

/**
 * Path utilities.
 */
final class Path
{
    /**
     * Resolve a path relative to a base directory if needed.
     *
     * @param string $path The path to resolve
     * @param string|null $baseDir Optional base directory for relative paths
     * @return string Absolute or resolved path
     */
    public static function resolve(string $path, ?string $baseDir = null): string
    {
        $isAbsolute = (bool) preg_match('#^([A-Za-z]:\\\\|/|\\\\\\\\)#', $path);
        if ($isAbsolute) {
            return $path;
        }
        $base = $baseDir ?: Env::root();
        return rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }
}
