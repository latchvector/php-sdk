<?php

declare(strict_types=1);

namespace LatchVector\Sso\Tenancy;

/**
 * Small helpers for materialised org paths ("/1/57/"). Centralised because both
 * correctness rules here are security-relevant:
 *
 *  - the TRAILING SLASH is what stops a prefix match from leaking into a sibling
 *    ("/2/5/" must not match "/2/57/"), so every path is normalised to carry one;
 *  - LIKE metacharacters in a path segment are escaped so a value can never act
 *    as a wildcard.
 */
final class OrgPath
{
    /** Ensure a non-empty path ends with a slash, so prefix matching is safe. */
    public static function normalize(string $path): string
    {
        if ($path === '' || str_ends_with($path, '/')) {
            return $path;
        }

        return $path . '/';
    }

    /** Escape LIKE metacharacters so a path segment can't act as a wildcard. */
    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
