<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Support;

final class Path
{
    public static function resolve(string $path, ?string $baseDir = null): string
    {
        $isAbsolute = (bool) preg_match('#^([A-Za-z]:\\\\|/|\\\\\\\\)#', $path);
        if ($isAbsolute) return $path;
        $base = $baseDir ?: Env::root();
        return rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }
}

