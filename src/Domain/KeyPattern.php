<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Domain;

/**
 * Groups cache keys that differ only by an identifier: `user:123` and
 * `user:456` are both `user:{id}`.
 *
 * Only values that stand alone are replaced — a number between separators,
 * not one inside a word — so `v2:config` stays as written. Replacement is
 * deterministic and ordered from the most specific shape to the least.
 */
final class KeyPattern
{
    /** @var array<string, string> regex => placeholder */
    private const RULES = [
        '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i' => '{uuid}',
        '/(?<![0-9A-Za-z])(?=[0-9A-Z]*[0-9])[0-7][0-9A-HJKMNP-TV-Z]{25}(?![0-9A-Za-z])/' => '{ulid}',
        '/[^\s:\/|@]+@[^\s:\/|@]+\.[a-z]{2,}/i' => '{email}',
        '/(?<![0-9])\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?Z?)?(?![0-9])/' => '{date}',
        '/(?<![0-9-])\d{4}-(?:0[1-9]|1[0-2])(?![0-9-])/' => '{date}',
        '/(?<![0-9a-z])(?=[0-9a-f]*[a-f])(?=[0-9a-f]*[0-9])[0-9a-f]{16,}(?![0-9a-z])/i' => '{hash}',
        '/(?<![0-9A-Za-z])\d+(?![0-9A-Za-z])/' => '{id}',
    ];

    public static function of(string $key): string
    {
        return (string) preg_replace(array_keys(self::RULES), array_values(self::RULES), $key);
    }
}
