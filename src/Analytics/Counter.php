<?php

namespace Website\Analytics;

use Kirby\Cms\Page;
use Kirby\Data\Json;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use Throwable;
use Website\Blog\JsonFile;

/**
 * Counts every public page view in PHP, without cookies, scripts or
 * external services. The route:after hook runs before Page::render() reads
 * the page cache, so cached pages count too.
 *
 * A visit is one browser on one day: an HMAC of IP address and user agent
 * with a random key for that day. Key and hashes live only in
 * site/cache/analytics/<date>.json (generated, never in a backup) and are
 * deleted with the first counted view on a later day. The daily totals
 * live in content/.analytics (Months).
 */
final class Counter
{
    /** No browser: crawlers, link previews, uptime checks, command line tools. */
    public const BOTS = '/bot|crawl|spider|slurp|archiver|facebookexternalhit|embedly|preview|lighthouse|headless|pingdom|uptime|monitor|curl|wget|python|java\/|okhttp|go-http/i';

    /** Key and hashes of the day. */
    private static function cacheDir(): string
    {
        return kirby()->root('cache') . '/analytics';
    }

    /** Hook handler for route:after. Returns nothing, so the result stays as it is. */
    public static function route(string $method, mixed $result, bool $final): void
    {
        if (!$final || $method !== 'GET') {
            return;
        }
        $id = $result instanceof Page ? self::pageId($result) : null;
        if ($id === null || !self::isVisitor()) {
            return;
        }
        // Feed readers rarely send a referrer: the links of the feed name their source.
        $source = ($_GET['source'] ?? null) === 'rss' ? 'RSS' : null;
        try {
            self::count($id, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', $_SERVER['HTTP_REFERER'] ?? '', $source);
        } catch (Throwable $e) {
            // A count must never break a page.
            error_log('blog analytics: ' . $e->getMessage());
        }
    }

    /**
     * The id of a page that counts, or null. Drafts open only with a preview
     * link and the error page is no page anyone asked for.
     */
    public static function pageId(Page $page): ?string
    {
        if ($page->isDraft() || $page->isErrorPage()) {
            return null;
        }
        return $page->id();
    }

    /**
     * A person in a browser, not logged in to the Panel.
     * Reads the cookie directly: Kirby's user() or Cookie::get() would mark the
     * response as private and skip the page cache.
     */
    public static function isVisitor(): bool
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $prefetch = ($_SERVER['HTTP_SEC_PURPOSE'] ?? $_SERVER['HTTP_PURPOSE'] ?? '') !== '';
        return str_contains($ua, 'Mozilla/')
            && !preg_match(self::BOTS, $ua)
            && !$prefetch
            && !isset($_COOKIE['kirby_session']);
    }

    /**
     * Adds one view of page $id. A new visit counts where it came from: the
     * $source a link names, else the host of the referrer. Parallel requests
     * wait for one lock.
     */
    public static function count(string $id, string $ip, string $ua, string $referer, ?string $source = null): void
    {
        $cache = self::cacheDir();
        Dir::make($cache);
        $lock = fopen($cache . '/.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            // After the lock: a request that waited over midnight must not
            // rotate the key of the new day back to the old one.
            $date = date('Y-m-d');
            $seenFile = "$cache/$date.json";
            try {
                $seen = is_file($seenFile) ? Json::read($seenFile) : [];
            } catch (Throwable) {
                $seen = [];
            }
            if (!isset($seen['key'])) {
                $seen = ['key' => bin2hex(random_bytes(32)), 'visitors' => []];
                // Also temp files a killed request left behind; .lock is hidden from glob.
                foreach (glob("$cache/*") ?: [] as $old) {
                    F::remove($old);
                }
            }
            $visitor = substr(hash_hmac('sha256', "$ip|$ua", $seen['key']), 0, 16);
            $newVisit = !isset($seen['visitors'][$visitor]);
            $seen['visitors'][$visitor] ??= [];
            $newPageVisit = !in_array($id, $seen['visitors'][$visitor], true);
            if ($newPageVisit) {
                $seen['visitors'][$visitor][] = $id;
            }

            // An unreadable month throws here: the count must not overwrite it.
            $month = Months::read(substr($date, 0, 7), true);
            $day = $month[$date] ?? ['visits' => 0, 'views' => 0, 'pages' => [], 'referrers' => []];
            $day['views']++;
            if ($newVisit) {
                $day['visits']++;
                $host = $source ?? self::referrer($referer);
                if ($host !== null) {
                    $day['referrers'][$host] = ($day['referrers'][$host] ?? 0) + 1;
                }
            }
            if ($newPageVisit) {
                $day['pages'][$id] = ($day['pages'][$id] ?? 0) + 1;
            }
            $month[$date] = $day;

            JsonFile::write($seenFile, $seen);
            Months::write(substr($date, 0, 7), $month);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** The host of an external referrer without "www.", or null. */
    public static function referrer(string $referer): ?string
    {
        $host = strtolower((string) parse_url($referer, PHP_URL_HOST));
        if ($host === '') {
            return null;
        }
        $strip = fn(string $h) => preg_replace('/^www\./', '', $h);
        $own = [$strip(strtolower((string) parse_url(kirby()->url(), PHP_URL_HOST))), $strip(strtolower($_SERVER['HTTP_HOST'] ?? ''))];
        return in_array($strip($host), $own, true) ? null : $strip($host);
    }
}
