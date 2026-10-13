<?php

/**
 * Pop's visits (var/data.db on the server, SQLite) as daily totals of
 * "Besucher" (content/.analytics), once, before Pop goes. Pop counted a
 * browser on a page at most once in 30 minutes; a row is one such view.
 * Only rows of this site count, an old URL counts for the page its
 * redirect leads to. A day Kirby has counted already stays as it is.
 */

const POP_SITE = 'https://sven.eisenschmidt.website';

/** The Kirby page id of a URL Pop counted, or null. */
function pop_page_id(string $url): ?string
{
    if ($url !== POP_SITE && !str_starts_with($url, POP_SITE . '/')) {
        return null;
    }
    $path = (string)parse_url($url, PHP_URL_PATH);
    $path = trim((string)parse_url(blog_redirect_to($path) ?? $path, PHP_URL_PATH), '/');
    if ($path === '') {
        return site()->homePage()?->id();
    }
    if (preg_match('!^(\d{4}-\d{2}-\d{2})-([a-z0-9-]+)$!', $path, $m)) {
        return blog_post($m[1], $m[2])?->id();
    }
    return site()->find($path)?->id();
}

/**
 * Adds the days of the database $db that "Besucher" does not have.
 * Returns the number of rows, of days written and kept, and the URLs
 * without a page with their rows.
 */
function pop_import(string $db): array
{
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $ids = [];
    $days = [];
    $unknown = [];
    $rows = 0;
    foreach ($pdo->query('SELECT page_id, fingerprint, visited_at FROM visits ORDER BY visited_at') as $row) {
        $rows++;
        $id = $ids[$row['page_id']] ??= pop_page_id($row['page_id']) ?? false;
        if ($id === false) {
            $unknown[$row['page_id']] = ($unknown[$row['page_id']] ?? 0) + 1;
            continue;
        }
        $date = date('Y-m-d', (int)$row['visited_at']);
        $days[$date]['visitors'][$row['fingerprint']] = true;
        $days[$date]['views'] = ($days[$date]['views'] ?? 0) + 1;
        $days[$date]['pages'][$id][$row['fingerprint']] = true;
    }

    $months = [];
    $written = 0;
    foreach ($days as $date => $day) {
        $month = substr($date, 0, 7);
        $months[$month] ??= blog_analytics_month($month, true);
        if (isset($months[$month][$date])) {
            continue;
        }
        $months[$month][$date] = [
            'visits' => count($day['visitors']),
            'views' => $day['views'],
            'pages' => array_map('count', $day['pages']),
            'referrers' => [],
        ];
        $written++;
    }
    foreach ($months as $month => $data) {
        ksort($data);
        blog_analytics_write(blog_analytics_dir() . "/$month.json", $data);
    }
    arsort($unknown);
    return ['rows' => $rows, 'days' => $written, 'kept' => count($days) - $written, 'unknown' => $unknown];
}
