<?php

/**
 * Requests every page of a site once, then every thumbnail under /media/
 * that the pages name (src, srcset, href, content), so Kirby renders each
 * thumbnail before the first visitor asks for it. Originals stay out: Kirby
 * only copies them on their first request, and together they are 4 GB.
 * Pages come from a crawl from the start page: links on the same host,
 * without query and without a file extension. Four requests at a time. The
 * user agent says "bot", so "Besucher" does not count the requests.
 * The staging password comes from STAGING_USER and STAGING_PASSWORD in the
 * environment, never from the command line.
 *
 * Usage: php bin/warm.php <site-url>
 */

$site = rtrim($argv[1] ?? '', '/');
if (!preg_match('!^https?://[^/]+$!', $site)) {
    fwrite(STDERR, "Usage: php bin/warm.php <site-url>\n");
    exit(1);
}
$headers = ['User-Agent: website-warm (bot)'];
if (getenv('STAGING_USER') && getenv('STAGING_PASSWORD')) {
    $headers[] = 'Authorization: Basic ' . base64_encode(getenv('STAGING_USER') . ':' . getenv('STAGING_PASSWORD'));
}

/** GETs the URLs, four at a time, and calls $done(url, status, body) for each. */
function warm_get(array $urls, array $headers, Closure $done): void
{
    $multi = curl_multi_init();
    $start = function (string $url) use ($multi, $headers): void {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_PRIVATE => $url,
        ]);
        curl_multi_add_handle($multi, $handle);
    };
    $queue = array_values($urls);
    $active = 0;
    while ($queue !== [] || $active > 0) {
        while ($active < 4 && $queue !== []) {
            $start(array_shift($queue));
            $active++;
        }
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 1.0);
        while ($info = curl_multi_info_read($multi)) {
            $handle = $info['handle'];
            $done((string)curl_getinfo($handle, CURLINFO_PRIVATE), (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE), (string)curl_multi_getcontent($handle));
            curl_multi_remove_handle($multi, $handle);
            $active--;
        }
    }
    curl_multi_close($multi);
}

/** The paths on this site that the HTML names: [pages, thumbnails]. */
function warm_links(string $html, string $site): array
{
    preg_match_all('!(?:href|src|content)="([^"]+)"!', $html, $single);
    preg_match_all('!srcset="([^"]+)"!', $html, $sets);
    $urls = $single[1];
    foreach ($sets[1] as $set) {
        foreach (explode(',', $set) as $candidate) {
            $urls[] = strtok(trim($candidate), ' ');
        }
    }
    $pages = $media = [];
    foreach ($urls as $url) {
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5);
        if (str_starts_with($url, $site . '/')) {
            $url = substr($url, strlen($site));
        }
        if (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
            continue;
        }
        $path = strtok($url, '#');
        if (str_starts_with($path, '/media/')) {
            // A thumbnail's name ends in its size: photo-600x-q80.webp, photo-480x480-crop-q80.webp.
            if (preg_match('!-\d+x\d*(-[a-z0-9-]+)?\.[a-z]+$!i', $path)) {
                $media[$path] = true;
            }
        } elseif (!str_contains($path, '?') && !preg_match('!^/(panel|api)(/|$)|\.[a-z0-9]+$!i', $path)) {
            $pages[$path] = true;
        }
    }
    return [array_keys($pages), array_keys($media)];
}

$started = microtime(true);
$seen = ['/' => true];
$media = [];
// Links in old posts may lead to a redirect or to nothing (404): noted, no failure.
$other = [];
$failed = [];
$level = ['/'];
while ($level !== []) {
    $next = [];
    warm_get(array_map(fn ($path) => $site . $path, $level), $headers, function (string $url, int $status, string $body) use ($site, &$seen, &$media, &$other, &$failed, &$next): void {
        if ($status !== 200) {
            if ($status >= 300 && $status < 500) {
                $other[] = "$status $url";
            } else {
                $failed[] = "$status $url";
            }
            return;
        }
        [$pages, $files] = warm_links($body, $site);
        foreach ($pages as $path) {
            if (!isset($seen[$path])) {
                $seen[$path] = true;
                $next[] = $path;
            }
        }
        foreach ($files as $path) {
            $media[$path] = true;
        }
    });
    $level = $next;
}
printf("%d pages in %.0f s, %d links to a redirect or 404\n", count($seen) - count($other), microtime(true) - $started, count($other));
foreach (array_slice($other, 0, 20) as $line) {
    echo "  $line\n";
}

$started = microtime(true);
warm_get(array_map(fn ($path) => $site . $path, array_keys($media)), $headers, function (string $url, int $status) use (&$failed): void {
    if ($status !== 200) {
        $failed[] = "$status $url";
    }
});
printf("%d thumbnails in %.0f s, %d failures\n", count($media), microtime(true) - $started, count($failed));
foreach (array_slice($failed, 0, 20) as $line) {
    echo "  $line\n";
}
exit($failed === [] ? 0 : 1);
