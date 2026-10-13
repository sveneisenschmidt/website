<?php

/**
 * Sends the URLs of the sitemap that changed in the last 26 hours to
 * IndexNow (Bing, Yandex, ...). The workflow IndexNow runs it once a day;
 * the two hours on top cover a run that starts late. The key is the name
 * of the file www/<key>.txt, which the site serves for IndexNow to check.
 *
 * Usage: php bin/indexnow.php <site-url>
 */

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
preg_match_all('!<loc>([^<]+)</loc>\s*<lastmod>([^<]+)</lastmod>!', $xml, $entries, PREG_SET_ORDER);
$since = time() - 26 * 3600;
$urls = [];
foreach ($entries as [, $loc, $lastmod]) {
    if (strtotime($lastmod) >= $since) {
        $urls[] = $loc;
    }
}
printf("%d of %d URLs changed in the last 26 hours\n", count($urls), count($entries));
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
