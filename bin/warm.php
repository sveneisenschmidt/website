<?php

/**
 * Requests every page of a site once, so Kirby writes it into the page cache.
 * Then it requests every thumbnail that the pages link, so Kirby renders it
 * before the first visitor asks for it. make warm runs it against the live
 * site.
 *
 * Pages: a crawl from the start page follows every link on the same host
 * that has no query and no file extension. The crawl also starts at
 * /index.xml and /sitemap.xml, because it skips links with an extension.
 * Thumbnails: every path under /media/ in a src, srcset, href or content
 * attribute whose file name ends in a size. Originals stay out: Kirby only
 * copies an original to media/ on its first request, and content/ held
 * 4.1 GB on 2026-10-13.
 *
 * The script sends 4 requests at a time. Its user agent contains "bot", so
 * the visitor count `Website\Analytics\Counter` skips it. With --pages, the
 * script stops after the pages. bin/deploy.sh runs it that way, because a
 * deploy clears the page cache but keeps the thumbnails in media/.
 *
 * Usage: php bin/warm.php [--pages] <site-url>
 */

/**
 * Sends a GET request to each URL, 4 at a time, and calls
 * $done(url, status, body) for each response.
 *
 * @param array<mixed> $headers
 * @param array<mixed> $urls
 */
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
            $done((string) curl_getinfo($handle, CURLINFO_PRIVATE), (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), (string) curl_multi_getcontent($handle));
            curl_multi_remove_handle($multi, $handle);
            $active--;
        }
    }
    curl_multi_close($multi);
}

/**
 * Returns the paths on this site that the HTML links: [pages, thumbnails].
 *
 * @return array{0: list<string>, 1: list<string>}
 */
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

// Tests require this file for the functions above; only a run as a script
// sends requests.
if (realpath($_SERVER['argv'][0] ?? '') !== __FILE__) {
    return;
}

$pagesOnly = ($argv[1] ?? '') === '--pages';
$site = rtrim($argv[$pagesOnly ? 2 : 1] ?? '', '/');
if (!preg_match('!^https?://[^/]+$!', $site)) {
    fwrite(STDERR, "Usage: php bin/warm.php [--pages] <site-url>\n");
    exit(1);
}
$headers = ['User-Agent: website-warm (bot)'];

$started = microtime(true);
$seen = ['/' => true, '/index.xml' => true, '/sitemap.xml' => true];
$media = [];
// Links in old posts can lead to a redirect or a 404. The script lists them
// but does not fail.
$other = [];
$failed = [];
$level = array_keys($seen);
while ($level !== []) {
    $next = [];
    warm_get(array_map(fn($path) => $site . $path, $level), $headers, function (string $url, int $status, string $body) use ($site, &$seen, &$media, &$other, &$failed, &$next): void {
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
if ($pagesOnly) {
    foreach (array_slice($failed, 0, 20) as $line) {
        echo "  $line\n";
    }
    exit($failed === [] ? 0 : 1);
}

$started = microtime(true);
$count = 0;
warm_get(array_map(fn($path) => $site . $path, array_keys($media)), $headers, function (string $url, int $status) use (&$failed, &$count, $media, $started): void {
    if ($status !== 200) {
        $failed[] = "$status $url";
    }
    // Kirby renders each thumbnail on its first request. That takes long, so
    // the script prints its progress.
    if (++$count % 100 === 0) {
        printf("  %d of %d thumbnails, %.0f s\n", $count, count($media), microtime(true) - $started);
    }
});
printf("%d thumbnails in %.0f s, %d failures\n", count($media), microtime(true) - $started, count($failed));
foreach (array_slice($failed, 0, 20) as $line) {
    echo "  $line\n";
}
exit($failed === [] ? 0 : 1);
