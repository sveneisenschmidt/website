<?php

/**
 * Checks today's URLs against Kirby: every URL of the live sitemap answers
 * 200 at exactly its address, slash at the end included; every redirect
 * of content/.redirects.json answers 301 to its target, and the target
 * answers 200.
 * Runs against the local server (make dev) or the live site.
 *
 * Usage: php bin/check-urls.php [site-url] [sitemap-url-or-file] [www-root]
 */

$site = rtrim($argv[1] ?? 'http://localhost:8010', '/');
$sitemap = $argv[2] ?? 'https://sven.eisenschmidt.website/sitemap.xml';
$www = rtrim($argv[3] ?? 'www', '/');

/**
 * Status and Location of a GET request, without following a redirect.
 *
 * @return array<mixed>
 */
function check_get(string $url): array
{
    $context = stream_context_create(['http' => ['follow_location' => 0, 'ignore_errors' => true, 'timeout' => 60]]);
    @file_get_contents($url, false, $context);
    $headers = http_get_last_response_headers() ?? [];
    $location = null;
    foreach ($headers as $header) {
        if (stripos($header, 'Location:') === 0) {
            $location = trim(substr($header, 9));
        }
    }
    return [(int) (explode(' ', $headers[0] ?? '')[1] ?? 0), $location];
}

preg_match_all('!<loc>([^<]+)</loc>!', (string) file_get_contents($sitemap), $m);
$redirects = json_decode((string) file_get_contents("$www/content/.redirects.json"), true) ?? [];

$failures = [];
$total = count($m[1]) + 2 * count($redirects);
$count = 0;
$started = microtime(true);
$expect = function (string $path, int $status, ?string $location = null) use ($site, &$failures, &$count, $total, $started): void {
    [$got, $to] = check_get($site . $path);
    if ($got !== $status || ($location !== null && $to !== $site . $location)) {
        $failures[] = "$path: $got" . ($to ? " -> $to" : '') . ", expected $status" . ($location ? " -> $location" : '');
    }
    // One request at a time, against the live site several minutes: show progress.
    if (++$count % 100 === 0) {
        printf("  %d of %d requests, %.0f s\n", $count, $total, microtime(true) - $started);
    }
};

foreach ($m[1] as $url) {
    $expect((string) parse_url($url, PHP_URL_PATH), 200);
}
foreach ($redirects as $redirect) {
    $expect($redirect['from'], 301, $redirect['to']);
    $expect((string) parse_url($redirect['to'], PHP_URL_PATH), 200);
}

printf("%d URLs from the sitemap, %d redirects with their targets, %d failures\n", count($m[1]), count($redirects), count($failures));
foreach ($failures as $failure) {
    echo "  $failure\n";
}
exit($failures === [] ? 0 : 1);
