<?php

namespace Website\Analytics;

use Kirby\Cms\Page;
use Kirby\Data\Json;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use Throwable;
use Website\Blog\JsonFile;

/**
 * Counts the public page views on the server, without cookies, scripts or
 * external services. plugins/analytics calls route() from Kirby's hook
 * route:after. That hook runs before Page::render() reads the page cache,
 * so views of cached pages count too.
 *
 * A visit is one browser on one day. The browser is identified by an HMAC
 * (a hash with a secret key) of IP address and user agent, with a random
 * key for that day. Key and hashes are stored only in
 * site/cache/analytics/<date>.json, which no backup includes. The first
 * counted view on a later day deletes them. The daily totals are in
 * content/.analytics (see Months).
 */
final class Counter
{
    /** User agents that are not a person in a browser: crawlers, link preview services, uptime checks, command line tools. */
    public const BOTS = '/bot|crawl|spider|slurp|archiver|facebookexternalhit|embedly|preview|lighthouse|headless|pingdom|uptime|monitor|curl|wget|python|java\/|okhttp|go-http/i';

    /**
     * The visitors with a hash per day. Requests with a new user agent each
     * would otherwise add hashes without end, and every later view reads and
     * writes the file of the day while it holds the lock. A view from a
     * further visitor counts as a view only.
     */
    public const MAX_VISITORS = 50000;

    /**
     * The referrer hosts per day. The hosts stay in the month file and in
     * every backup. A visit from a further host counts under `OTHER`, as does
     * a host longer than `MAX_HOST` characters.
     */
    public const MAX_REFERRERS = 200;

    public const MAX_HOST = 100;

    /** The referrer for the hosts beyond the limits. The Panel shows it as it is. */
    public const OTHER = 'Andere';

    /** The folder for the key and the hashes of the current day. */
    private static function cacheDir(): string
    {
        return kirby()->root('cache') . '/analytics';
    }

    /**
     * Handler for the hook route:after. Kirby replaces the route result with
     * every return value of this hook other than null, so the method returns
     * nothing.
     */
    public static function route(string $method, mixed $result, bool $final): void
    {
        if (!$final || $method !== 'GET') {
            return;
        }
        $id = $result instanceof Page ? self::pageId($result) : null;
        if ($id === null || !self::isVisitor()) {
            return;
        }
        // Feed readers rarely send a Referer header. The links in the feed
        // add ?source=rss, so the visit counts as coming from the feed.
        $source = Campaign::source() === 'rss' ? 'RSS' : null;
        try {
            self::count($id, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', $_SERVER['HTTP_REFERER'] ?? '', $source);
        } catch (Throwable $e) {
            // An error while counting must not fail the page request.
            error_log('blog analytics: ' . $e->getMessage());
        }
    }

    /**
     * The id of the page to count, or null. A draft does not count, because
     * only a preview link opens it. The error page does not count, because
     * the requested page does not exist.
     */
    public static function pageId(Page $page): ?string
    {
        if ($page->isDraft() || $page->isErrorPage()) {
            return null;
        }
        return $page->id();
    }

    /**
     * True for a person in a browser who is not logged in to the Panel. A
     * prefetch request (header Sec-Purpose or Purpose) does not count,
     * because the visitor has not opened the page yet. The method reads
     * $_COOKIE directly: Kirby's user() or Cookie::get() would mark the
     * response as private, and Kirby would not cache the page.
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
     * Adds one view of page $id to the totals of today. The first view of a
     * visit also counts where the visitor came from: $source when the link
     * names one, else the host of the referrer. Parallel requests wait for
     * the lock on site/cache/analytics/.lock.
     */
    public static function count(string $id, string $ip, string $ua, string $referer, ?string $source = null): void
    {
        $cache = self::cacheDir();
        Dir::make($cache);
        $lock = fopen($cache . '/.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            // The date is read after the lock is taken. A request that waited
            // past midnight would otherwise use the old date and delete the
            // key and the hashes of the new day.
            $date = date('Y-m-d');
            $seenFile = "$cache/$date.json";
            try {
                $seen = is_file($seenFile) ? Json::read($seenFile) : [];
            } catch (Throwable) {
                $seen = [];
            }
            if (!isset($seen['key'])) {
                $seen = ['key' => bin2hex(random_bytes(32)), 'visitors' => []];
                // A new day deletes all files of earlier days, also temporary
                // files that a killed request left. glob("*") skips dot
                // files, so .lock stays.
                foreach (glob("$cache/*") ?: [] as $old) {
                    F::remove($old);
                }
            }
            $visitor = substr(hash_hmac('sha256', "$ip|$ua", $seen['key']), 0, 16);
            $known = isset($seen['visitors'][$visitor]);
            $newVisit = !$known && count($seen['visitors']) < self::MAX_VISITORS;
            $newPageVisit = false;
            if ($known || $newVisit) {
                $seen['visitors'][$visitor] ??= [];
                $newPageVisit = !in_array($id, $seen['visitors'][$visitor], true);
                if ($newPageVisit) {
                    $seen['visitors'][$visitor][] = $id;
                }
            }

            // For an unreadable month file, the strict read throws. Without
            // the exception, this count would overwrite the file with one day.
            $month = Months::read(substr($date, 0, 7), true);
            $day = $month[$date] ?? ['visits' => 0, 'views' => 0, 'pages' => [], 'referrers' => []];
            $day['views']++;
            if ($newVisit) {
                $day['visits']++;
                $host = $source ?? self::referrer($referer);
                if ($host !== null) {
                    if (strlen($host) > self::MAX_HOST || (!isset($day['referrers'][$host]) && count($day['referrers']) >= self::MAX_REFERRERS)) {
                        $host = self::OTHER;
                    }
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

    /** The host of the referrer without "www.", or null when there is none or when it is this site. */
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
