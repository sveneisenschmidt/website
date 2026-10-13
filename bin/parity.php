<?php

/**
 * Compares every Kirby page with the page Hugo built (public/), after a
 * normalisation that leaves out what is meant to differ:
 * - white space between tags and runs of white space,
 * - how HTML is written (quotes, entities, "/>"): both sides go through
 *   PHP's HTML5 parser and come out the same way,
 * - Hugo's generator tag, Pop (script, preconnect) and Pagefind attributes,
 * - the content of inline CSS and JS (Hugo minified it): each script
 *   becomes [js:name], the style [css],
 * - URLs: the host, image URLs (Hugo's _hu_ names, Kirby's media URLs)
 *   as [img:name] and [file:name], asset URLs as [asset:name],
 * - JSON (JSON-LD, map points) decoded and encoded again.
 * Writes both normalised pages to .parity/ (one tag per line, for diff)
 * and prints each page that differs with the elements that differ.
 *
 * Usage: php bin/parity.php [www-root] [hugo-public]
 */

$www = rtrim($argv[1] ?? 'www', '/');
$public = rtrim($argv[2] ?? 'public', '/');
require dirname(__DIR__) . '/vendor/autoload.php';
require $www . '/kirby/bootstrap.php';

const PARITY_HOST = 'https://sven.eisenschmidt.website';
// Which inline script is which: a string each one holds, minified or not.
const PARITY_SCRIPTS = ['stream-scroll' => 'scroll', 'data-back' => 'back', 'data-fit' => 'fit', 'copy-button' => 'copy', 'data-search-input' => 'search'];

$kirby = new Kirby([
    'roots' => ['index' => realpath($www)],
    'options' => ['url' => PARITY_HOST, 'cache' => ['pages' => ['active' => false]]],
]);

/** A URL as both sides can agree on it. */
function parity_url(string $url): string
{
    // Hugo wrote %C3%9C where Kirby writes Ü: the same URL.
    $url = rawurldecode(str_starts_with($url, PARITY_HOST) ? substr($url, strlen(PARITY_HOST)) : $url);
    $path = (string)parse_url($url, PHP_URL_PATH);
    $name = strtolower(basename($path));
    // Hugo: /<post>/<name>_hu_<hash>.webp; Kirby: /media/pages/<id>/<hash>/<name>-<options>.webp
    if (preg_match('/^(.+)_hu_[0-9a-f]+\.\w+$/', $name, $m) || (str_starts_with($path, '/media/') && preg_match('/^(.+?)-\d*x\d*(-[a-z0-9-]+)?\.\w+$/', $name, $m))) {
        return '[img:' . $m[1] . ']';
    }
    if (preg_match('/\.(jpe?g|png|webp|gif)$/', $name)) {
        return '[file:' . $name . ']';
    }
    // Hugo: /leaflet/leaflet.min.<hash>.css; Kirby: /assets/leaflet/leaflet.css?v=<time>
    if (preg_match('!^(/assets)?/(js|leaflet)/(\w+)(\.min)?(\.[0-9a-f]{64})?\.(css|js)$!', $path, $m)) {
        return '[asset:' . $m[3] . '.' . $m[6] . ']';
    }
    return $url;
}

/** The page as one string, one tag per line. */
function parity_normalise(string $html): string
{
    $html = preg_replace([
        '!<meta name="generator"[^>]*>!',
        '!<link rel="(dns-prefetch|preconnect)" href="https://pop\.[^>]*>!',
        '!<script src="https://pop\.[^>]*></script>!',
        '!<script defer>\s*document\.addEventListener\("DOMContentLoaded", function \(\) \{\s*if \(window\.location\.hostname.*?</script>!s',
        '! data-pagefind-(body|ignore|meta="[^"]*")!',
    ], '', $html);
    $doc = Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
    foreach ($doc->querySelectorAll('style') as $style) {
        $style->textContent = '[css]';
    }
    foreach ($doc->querySelectorAll('script') as $script) {
        $type = $script->getAttribute('type');
        if ($script->hasAttribute('src')) {
            continue;
        }
        if ($type === 'application/ld+json' || $type === 'application/json') {
            $data = json_decode($script->textContent, true);
            if (is_array($data)) {
                array_walk_recursive($data, function (&$value) {
                    $value = is_string($value) && str_starts_with($value, 'http') ? parity_url($value) : $value;
                });
            }
            $script->textContent = $data === null ? trim($script->textContent) : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            continue;
        }
        $name = 'unknown';
        foreach (PARITY_SCRIPTS as $marker => $js) {
            if (str_contains($script->textContent, $marker)) {
                $name = $js;
                break;
            }
        }
        $script->textContent = "[js:$name]";
    }
    foreach ($doc->querySelectorAll('[src], [href], [srcset], meta[content^="http"], [data-points]') as $el) {
        foreach (['src', 'href', 'content'] as $attr) {
            if ($el->hasAttribute($attr)) {
                $el->setAttribute($attr, parity_url($el->getAttribute($attr)));
            }
        }
        if ($el->hasAttribute('srcset')) {
            $el->setAttribute('srcset', implode(', ', array_map(function ($item) {
                [$url, $size] = preg_split('/\s+/', trim($item)) + [1 => ''];
                return parity_url($url) . ' ' . $size;
            }, explode(',', $el->getAttribute('srcset')))));
        }
        if ($el->hasAttribute('data-points')) {
            $el->setAttribute('data-points', json_encode(json_decode($el->getAttribute('data-points'), true), JSON_UNESCAPED_UNICODE));
        }
    }
    // Hugo put runs of images into a <p>, which the browser closes empty.
    foreach ($doc->querySelectorAll('p:empty') as $p) {
        $p->remove();
    }
    $out = $doc->saveHtml();
    $out = preg_replace(['/\s+/u', '/\s*(<[^>]+>)\s*/u'], [' ', '$1'], $out);
    return preg_replace('/(?<!^)(<[a-z])/', "\n$1", $out);
}

/** What a changed line is: tag, class and name, as "meta[name=description]" or "figure". */
function parity_category(string $line): string
{
    if (!preg_match('/^<([a-z0-9]+)/', $line, $m)) {
        return 'text';
    }
    $key = '';
    if (preg_match('/ (name|property|rel|class)="([^"]*)"/', $line, $a)) {
        $key = '[' . $a[1] . '=' . explode(' ', $a[2])[0] . ']';
    }
    return $m[1] . $key;
}

$pages = [['/', $kirby->site()->homePage(), "$public/index.html"], ['/404.html', $kirby->site()->errorPage(), "$public/404.html"]];
foreach ($kirby->site()->index() as $page) {
    if ($page->isHomePage() || $page->isErrorPage() || in_array($page->id(), ['photos', 'trips', 'notes', 'essays'], true)) {
        continue;
    }
    $path = (string)parse_url($page->url(), PHP_URL_PATH);
    $pages[] = [$path, $page, "$public" . rtrim($path, '/') . '/index.html'];
}

$out = dirname(__DIR__) . '/.parity';
exec('rm -rf ' . escapeshellarg($out));
$equal = 0;
$categories = [];
$missing = [];
foreach ($pages as [$path, $page, $file]) {
    if (!is_file($file)) {
        $missing[] = $path;
        continue;
    }
    $kirby->site()->visit($page);
    try {
        $a = parity_normalise($page->render());
    } catch (Throwable $e) {
        echo "fails: $path: " . $e->getMessage() . "\n";
        $categories['render error'][] = $path;
        continue;
    }
    $b = parity_normalise(file_get_contents($file));
    if ($a === $b) {
        $equal++;
        continue;
    }
    $name = trim($path, '/') === '' ? 'index' : str_replace('/', '_', trim($path, '/'));
    @mkdir("$out/kirby", 0755, true);
    @mkdir("$out/hugo", 0755, true);
    file_put_contents("$out/kirby/$name.html", $a . "\n");
    file_put_contents("$out/hugo/$name.html", $b . "\n");
    exec('diff ' . escapeshellarg("$out/hugo/$name.html") . ' ' . escapeshellarg("$out/kirby/$name.html"), $diff);
    // Each block of changed lines counts by its first line.
    $found = [];
    $first = true;
    foreach ($diff as $line) {
        if (preg_match('/^\d/', $line)) {
            $first = true;
        } elseif ($first && preg_match('/^[<>] (.*)$/', $line, $m)) {
            $found[parity_category($m[1])] = true;
            $first = false;
        }
    }
    $diff = [];
    foreach (array_keys($found) as $category) {
        $categories[$category][] = $path;
    }
    echo "differs: $path (" . implode(', ', array_keys($found)) . ")\n";
}

printf("\n%d pages, %d equal, %d differ, %d without a Hugo page\n", count($pages), $equal, count($pages) - $equal - count($missing), count($missing));
foreach ($missing as $path) {
    echo "  no Hugo page: $path\n";
}
ksort($categories);
foreach ($categories as $category => $paths) {
    printf("  %-40s %4d pages, e.g. %s\n", $category, count($paths), $paths[0]);
}
echo "Normalised pages that differ: .parity/hugo and .parity/kirby (diff -r .parity/hugo .parity/kirby)\n";

/** The items of a feed by link: GUID as written, the rest normalised. */
function parity_feed(string $xml): array
{
    $items = [];
    foreach (simplexml_load_string($xml, null, LIBXML_NOCDATA)->channel->item as $item) {
        $description = preg_replace_callback('/src="([^"]+)"/', fn ($m) => 'src="' . parity_url($m[1]) . '"', (string)$item->description);
        // Hugo wrote &#43; and &#39; where Kirby writes + and &#039;: the same text.
        $description = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $items[(string)$item->link] = [(string)$item->guid, (string)$item->title . (string)$item->pubDate . $description];
    }
    return $items;
}

$hugo = parity_feed(file_get_contents("$public/index.xml"));
$feed = parity_feed(blog_rss());
$guids = $same = 0;
foreach ($hugo as $link => [$guid, $rest]) {
    $guids += ($feed[$link][0] ?? null) === $guid ? 1 : 0;
    if (($feed[$link][1] ?? null) === $rest) {
        $same++;
    } else {
        echo "feed item differs: $link\n";
    }
}
printf("\nFeed: %d items in Hugo, %d in Kirby, %d GUIDs byte-identical, %d items equal\n", count($hugo), count($feed), $guids, $same);

/** The entries of a sitemap: loc => lastmod and image. */
function parity_sitemap(string $xml): array
{
    $urls = [];
    $doc = simplexml_load_string($xml);
    foreach ($doc->url as $url) {
        $image = $url->children('http://www.google.com/schemas/sitemap-image/1.1')->image;
        $urls[parity_url((string)$url->loc)] = (string)$url->lastmod . ' ' . ($image ? parity_url((string)$image->loc) : '');
    }
    return $urls;
}

$hugo = parity_sitemap(file_get_contents("$public/sitemap.xml"));
$map = parity_sitemap(blog_sitemap());
printf("Sitemap: %d URLs in Hugo, %d in Kirby, %d equal\n", count($hugo), count($map), count(array_intersect_assoc($hugo, $map)));
foreach (array_diff_assoc($hugo, $map) as $loc => $entry) {
    echo "  sitemap differs: $loc\n";
}
