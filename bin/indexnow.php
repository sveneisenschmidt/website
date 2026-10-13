<?php

/**
 * Tells IndexNow which pages changed. IndexNow is a protocol that search
 * engines such as Bing and Yandex use to learn about new URLs. The script
 * sends every sitemap URL whose `lastmod` lies in the last 50 hours.
 * The workflow IndexNow runs it once a day. 50 hours cover one failed or
 * late run, and IndexNow accepts a URL that comes twice.
 * The key is the name of the file www/<key>.txt. IndexNow fetches that file
 * from the site to check that the key belongs to the site.
 *
 * Usage: php bin/indexnow.php <site-url>
 */

/**
 * The URLs of a sitemap whose `lastmod` lies at or after $since, and the
 * number of URLs in the sitemap.
 *
 * @return array{0: list<string>, 1: int}
 */
function indexnow_changed(string $sitemap, int $since): array
{
    preg_match_all('!<loc>([^<]+)</loc>\s*<lastmod>([^<]+)</lastmod>!', $sitemap, $entries, PREG_SET_ORDER);
    $urls = [];
    foreach ($entries as [, $loc, $lastmod]) {
        if (strtotime($lastmod) >= $since) {
            $urls[] = $loc;
        }
    }
    return [$urls, count($entries)];
}

// Tests require this file for the function above; only a run as a script
// sends anything.
if (realpath($_SERVER['argv'][0] ?? '') !== __FILE__) {
    return;
}

$site = rtrim($argv[1] ?? '', '/');
$host = parse_url($site, PHP_URL_HOST);
if (!preg_match('!^https://[^/]+$!', $site) || !is_string($host)) {
    fwrite(STDERR, "Usage: php bin/indexnow.php <site-url>\n");
    exit(1);
}
$keys = glob(dirname(__DIR__) . '/www/*-*-*-*-*.txt') ?: [];
if (count($keys) !== 1) {
    fwrite(STDERR, "Expected exactly one key file www/<key>.txt, found " . count($keys) . "\n");
    exit(1);
}

$xml = @file_get_contents("$site/sitemap.xml");
if ($xml === false) {
    fwrite(STDERR, "Cannot read $site/sitemap.xml\n");
    exit(1);
}
[$urls, $total] = indexnow_changed($xml, time() - 50 * 3600);
printf("%d of %d URLs changed in the last 50 hours\n", count($urls), $total);
if ($urls === []) {
    exit(0);
}

$context = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/json; charset=utf-8\r\n",
    'content' => json_encode(['host' => $host, 'key' => basename($keys[0], '.txt'), 'urlList' => $urls]),
    'ignore_errors' => true,
    'timeout' => 60,
]]);
@file_get_contents('https://api.indexnow.org/indexnow', false, $context);
$status = (int) (explode(' ', (http_get_last_response_headers() ?? [''])[0])[1] ?? 0);
echo "IndexNow: HTTP $status\n";
exit($status >= 200 && $status < 300 ? 0 : 1);
