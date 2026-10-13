<?php

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Data\Json;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use Kirby\Http\Route;

/**
 * Visitor statistics without cookies, scripts or external services.
 * The route:after hook counts every public page view in PHP. It runs before
 * Page::render() reads the page cache, so cached pages count too.
 *
 * A visit is one browser on one day: an HMAC of IP address and user agent
 * with a random key for that day. Key and hashes live only in
 * site/cache/analytics/<date>.json (generated, never in a backup) and are
 * deleted with the first counted view on a later day. The daily totals live in
 * content/.analytics/<year>-<month>.json: state, like the change log.
 * The Panel shows them under "Besucher".
 */

/** No browser: crawlers, link previews, uptime checks, command line tools. */
const BLOG_ANALYTICS_BOTS = '/bot|crawl|spider|slurp|archiver|facebookexternalhit|embedly|preview|lighthouse|headless|pingdom|uptime|monitor|curl|wget|python|java\/|okhttp|go-http/i';

function blog_analytics_dir(): string
{
    return kirby()->root('content') . '/.analytics';
}

function blog_analytics_cache_dir(): string
{
    return kirby()->root('cache') . '/analytics';
}

/** Hook handler for route:after. Returns nothing, so the result stays as it is. */
function blog_analytics_route(Route $route, string $path, string $method, mixed $result, bool $final): void
{
    if (!$final || $method !== 'GET') {
        return;
    }
    $id = $result instanceof Page ? blog_analytics_page_id($result) : null;
    if ($id === null || !blog_analytics_is_visitor()) {
        return;
    }
    // Feed readers rarely send a referrer: the links of the feed name their source.
    $source = ($_GET['source'] ?? null) === 'rss' ? 'RSS' : null;
    try {
        blog_analytics_count($id, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', $_SERVER['HTTP_REFERER'] ?? '', $source);
    } catch (Throwable $e) {
        // A count must never break a page.
        error_log('blog analytics: ' . $e->getMessage());
    }
}

/**
 * The id of a page that counts, or null. Drafts open only with a preview
 * link and the error page is no page anyone asked for.
 */
function blog_analytics_page_id(Page $page): ?string
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
function blog_analytics_is_visitor(): bool
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $prefetch = ($_SERVER['HTTP_SEC_PURPOSE'] ?? $_SERVER['HTTP_PURPOSE'] ?? '') !== '';
    return str_contains($ua, 'Mozilla/')
        && !preg_match(BLOG_ANALYTICS_BOTS, $ua)
        && !$prefetch
        && !isset($_COOKIE['kirby_session']);
}

/**
 * Adds one view of page $id. A new visit counts where it came from: the
 * $source a link names, else the host of the referrer. Parallel requests
 * wait for one lock.
 */
function blog_analytics_count(string $id, string $ip, string $ua, string $referer, ?string $source = null): void
{
    $cache = blog_analytics_cache_dir();
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
        $month = blog_analytics_month(substr($date, 0, 7), true);
        $day = $month[$date] ?? ['visits' => 0, 'views' => 0, 'pages' => [], 'referrers' => []];
        $day['views']++;
        if ($newVisit) {
            $day['visits']++;
            $host = $source ?? blog_analytics_referrer($referer);
            if ($host !== null) {
                $day['referrers'][$host] = ($day['referrers'][$host] ?? 0) + 1;
            }
        }
        if ($newPageVisit) {
            $day['pages'][$id] = ($day['pages'][$id] ?? 0) + 1;
        }
        $month[$date] = $day;

        blog_write_json("$cache/$date.json", $seen);
        blog_write_json(blog_analytics_dir() . '/' . substr($date, 0, 7) . '.json', $month);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** The host of an external referrer without "www.", or null. */
function blog_analytics_referrer(string $referer): ?string
{
    $host = strtolower((string) parse_url($referer, PHP_URL_HOST));
    if ($host === '') {
        return null;
    }
    $strip = fn(string $h) => preg_replace('/^www\./', '', $h);
    $own = [$strip(strtolower((string) parse_url(kirby()->url(), PHP_URL_HOST))), $strip(strtolower($_SERVER['HTTP_HOST'] ?? ''))];
    return in_array($strip($host), $own, true) ? null : $strip($host);
}

/**
 * The days of one month ("2026-10"), keyed by date. An unreadable file
 * throws when $strict, else it reads as empty, so the Panel still opens.
 */
function blog_analytics_month(string $month, bool $strict = false): array
{
    $file = blog_analytics_dir() . "/$month.json";
    try {
        return is_file($file) ? Json::read($file) : [];
    } catch (Throwable $e) {
        if ($strict) {
            throw $e;
        }
        error_log('blog analytics: ' . $e->getMessage());
        return [];
    }
}

/** Totals for the Panel over the last $days days, today included. */
function blog_analytics_summary(int $days = 30): array
{
    $months = [];
    $list = [];
    $pages = [];
    $referrers = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $months[substr($date, 0, 7)] ??= blog_analytics_month(substr($date, 0, 7));
        $day = $months[substr($date, 0, 7)][$date] ?? [];
        $list[] = ['date' => $date, 'visits' => $day['visits'] ?? 0, 'views' => $day['views'] ?? 0];
        foreach ($day['pages'] ?? [] as $id => $n) {
            $pages[$id] = ($pages[$id] ?? 0) + $n;
        }
        foreach ($day['referrers'] ?? [] as $host => $n) {
            $referrers[$host] = ($referrers[$host] ?? 0) + $n;
        }
    }
    arsort($pages);
    arsort($referrers);
    $sum = fn(array $days) => array_sum(array_column($days, 'visits'));
    return [
        'days' => $list,
        'totals' => [
            'today' => end($list)['visits'],
            'week' => $sum(array_slice($list, -7)),
            'month' => $sum($list),
            'views' => array_sum(array_column($list, 'views')),
        ],
        'pages' => array_map(
            fn($id) => [...blog_analytics_page($id), 'visits' => $pages[$id]],
            array_slice(array_keys($pages), 0, 20),
        ),
        'referrers' => array_map(
            fn($host) => ['host' => $host, 'visits' => $referrers[$host]],
            array_slice(array_keys($referrers), 0, 10),
        ),
    ];
}

/**
 * All counted days together, for the block on About: visits, views, the
 * number of pages and the five posts with the most visits (id => visits).
 */
function blog_analytics_totals(): array
{
    $totals = ['visits' => 0, 'views' => 0, 'pages' => [], 'top' => []];
    foreach (glob(blog_analytics_dir() . '/*.json') ?: [] as $file) {
        foreach (blog_analytics_month(basename($file, '.json')) as $day) {
            $totals['visits'] += $day['visits'] ?? 0;
            $totals['views'] += $day['views'] ?? 0;
            foreach ($day['pages'] ?? [] as $id => $n) {
                $totals['pages'][$id] = ($totals['pages'][$id] ?? 0) + $n;
            }
        }
    }
    $posts = array_filter($totals['pages'], fn($id) => ($page = page($id)) !== null && blog_is_post($page), ARRAY_FILTER_USE_KEY);
    arsort($posts);
    $totals['top'] = array_slice($posts, 0, 5, true);
    $totals['pages'] = count($totals['pages']);
    return $totals;
}

/**
 * The visitor numbers on About: visits, views, pages,
 * and the most visited posts as [post, visits]. Null before the first visit.
 */
function blog_analytics_stats(): ?array
{
    $totals = blog_analytics_totals();
    if ($totals['visits'] === 0) {
        return null;
    }
    $top = [];
    foreach ($totals['top'] as $id => $visits) {
        $top[] = [page($id), $visits];
    }
    return ['top' => $top] + $totals;
}

/** Title and public URL of a counted page, also for deleted pages. */
function blog_analytics_page(string $id): array
{
    $page = page($id);
    if ($page === null) {
        return ['id' => $id, 'title' => "$id (gelöscht)", 'url' => null];
    }
    return ['id' => $id, 'title' => $page->isHomePage() ? 'Startseite' : $page->title()->value(), 'url' => $page->url()];
}

App::plugin('sveneisenschmidt/analytics', [
    'hooks' => [
        'route:after' => fn(Route $route, string $path, string $method, mixed $result, bool $final) => blog_analytics_route($route, $path, $method, $result, $final),
    ],
    'areas' => [
        'besucher' => fn() => [
            'label' => 'Besucher',
            'icon' => 'chart',
            'menu' => true,
            'link' => 'besucher',
            'views' => [[
                'pattern' => 'besucher',
                'action' => fn() => [
                    'component' => 'k-blog-analytics-view',
                    'title' => 'Besucher',
                    'props' => ['summary' => blog_analytics_summary(30)],
                ],
            ]],
        ],
    ],
]);
