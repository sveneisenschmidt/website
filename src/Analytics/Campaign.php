<?php

namespace Website\Analytics;

/**
 * Query parameters that say where a visit came from and do not change the
 * page: `source` (the feed links add source=rss), `utm_*`, `fbclid` and
 * `gclid`. Kirby skips its page cache for every request with a query
 * string, so www/index.php calls `strip()` before Kirby starts. `Counter`
 * reads `source` from here. A redirect to the canonical URL of a page adds
 * the parameters again, so the next request still counts its source.
 */
final class Campaign
{
    /** @var array<string, string> */
    private static array $params = [];

    /** Moves the parameters out of $_GET. Kirby reads $_GET only when it first needs the query. */
    public static function strip(): void
    {
        self::$params = [];
        foreach ($_GET as $key => $value) {
            if (is_string($value) && (in_array($key, ['source', 'fbclid', 'gclid'], true) || str_starts_with((string) $key, 'utm_'))) {
                self::$params[(string) $key] = $value;
                unset($_GET[$key]);
            }
        }
    }

    /** @return array<string, string> */
    public static function params(): array
    {
        return self::$params;
    }

    public static function source(): ?string
    {
        return self::$params['source'] ?? null;
    }
}
