<?php

/**
 * Checks that a site answers every URL it has published. Every URL of the
 * live sitemap must answer 200 at its exact address, with or without the
 * slash at the end as the sitemap lists it. Every redirect in
 * content/.redirects.json must answer 301 to its target, and the target
 * must answer 200. make check-urls runs it against make dev or the live site.
 *
 * Usage: php bin/check-urls.php <site-url> <sitemap-url-or-file> [www-root]
 */

if (!isset($argv[1], $argv[2])) {
    fwrite(STDERR, "Usage: php bin/check-urls.php <site-url> <sitemap-url-or-file> [www-root]\n");
    exit(1);
}
$site = rtrim($argv[1], '/');
$sitemap = $argv[2];
$www = rtrim($argv[3] ?? 'www', '/');

/**
 * Returns the status and the Location header of a GET request. It does not
 * follow a redirect.
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
    // The requests run one at a time and take several minutes against the
    // live site, so the script prints its progress.
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
