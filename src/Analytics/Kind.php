<?php

namespace Website\Analytics;

/**
 * The rules that Totals applies when the Panel or About reads the stored
 * requests. A changed rule therefore applies to old requests too.
 *
 * The user agent is the name that a client sends about itself, and every
 * program can choose it freely. Two headers show a real browser:
 * Sec-Fetch-Dest and Accept-Language. Chrome sends both since 2019, Firefox
 * since 2021 and Safari since 2023.
 */
final class Kind
{
    /** The user agent names a browser, and the request has both headers. */
    public const BROWSER = 'browser';

    /** The user agent names a program, such as Googlebot or curl. */
    public const BOT = 'bot';

    /** The user agent names a browser, but a header is missing. */
    public const SUSPECT = 'suspect';

    /** Words in the user agent of a program: crawlers, link preview services, uptime checks, command line tools. */
    public const BOT_WORDS = 'bot|crawl|spider|slurp|archiver|facebookexternalhit|embedly|preview|lighthouse|headless|pingdom|uptime|monitor|curl|wget|python|java\/|okhttp|go-http';

    public static function of(string $agent, bool $fetchDest, bool $language): string
    {
        if (!self::claimsBrowser($agent)) {
            return self::BOT;
        }
        return $fetchDest && $language ? self::BROWSER : self::SUSPECT;
    }

    /** True when the user agent names a browser and contains no word of BOT_WORDS. */
    public static function claimsBrowser(string $agent): bool
    {
        return str_contains($agent, 'Mozilla/') && !preg_match('/' . self::BOT_WORDS . '/i', $agent);
    }

    /**
     * The name of a bot for the Panel. A user agent that starts with
     * "Mozilla/" gives the word with a word of BOT_WORDS in it, such as
     * "Googlebot" in "Mozilla/5.0 (compatible; Googlebot/2.1)". Any other
     * user agent gives its first word, such as "Feedly" in "Feedly/1.0".
     */
    public static function botName(string $agent): string
    {
        $pattern = str_starts_with($agent, 'Mozilla/') ? '/[\w.-]*(?:' . self::BOT_WORDS . ')[\w.-]*/i' : '/^[^\/\s;(]+/';
        return preg_match($pattern, $agent, $m) ? explode('/', $m[0])[0] : 'ohne Namen';
    }

    /**
     * Where a visit came from: the host of a referrer from another site,
     * without "www.", else "RSS" for a link from the feed (?source=rss in
     * $uri), else null.
     */
    public static function origin(?string $referer, string $uri): ?string
    {
        $host = self::host((string) $referer);
        $own = [self::host(kirby()->url()), self::host('//' . ($_SERVER['HTTP_HOST'] ?? ''))];
        if ($host !== '' && !in_array($host, $own, true)) {
            return $host;
        }
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        return ($query['source'] ?? null) === 'rss' ? 'RSS' : null;
    }

    private static function host(string $url): string
    {
        return (string) preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
    }
}
