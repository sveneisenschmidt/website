<?php

use Kirby\Cms\File;
use Kirby\Cms\FileVersion;
use Kirby\Cms\Page;
use Kirby\Filesystem\F;
use Kirby\Toolkit\Str;

/**
 * What the frame of a page needs (snippets/layout.php, snippets/head.php)
 * and the thumbnails of the templates.
 */

/**
 * What the head says about a page: title, description, URL, whether it is
 * an article (a post or About) and the picture for link previews.
 */
function blog_meta(Page $page): array
{
    $site = $page->site()->title()->value();
    $cover = blog_share_cover($page);
    return [
        'title' => $page->isHomePage() ? $site : $page->title()->value() . ' · ' . $site,
        'description' => blog_description($page),
        'article' => blog_is_post($page) || $page->intendedTemplate()->name() === 'about',
        'url' => $page->url(),
        'image' => $cover?->thumb(['width' => 1200, 'height' => 630, 'crop' => true, 'format' => 'webp', 'quality' => 80])->url(),
    ];
}

/**
 * JSON-LD: the person on the home page, the article on a post, else null.
 * Job title and employer come from About; an empty field leaves its key out.
 */
function blog_jsonld(Page $page, array $meta): ?array
{
    $home = $page->site()->url() . '/';
    if ($page->isHomePage()) {
        $about = page('about');
        $employer = (string) $about?->employer()->value();
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Person',
            'name' => BLOG_SITE['author'],
            'url' => $home,
            'jobTitle' => (string) $about?->jobtitle()->value(),
            'worksFor' => $employer === '' ? null : array_filter(['@type' => 'Organization', 'name' => $employer, 'url' => (string) $about->employerurl()->value()]),
            'sameAs' => [BLOG_SITE['githubUrl'], BLOG_SITE['linkedinUrl'], BLOG_SITE['stravaUrl']],
        ]);
    }
    if (!blog_is_post($page)) {
        return null;
    }
    return array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $page->title()->value(),
        'datePublished' => $page->date()->toDate('c'),
        'dateModified' => date('c', blog_modified($page)),
        'image' => $meta['image'],
        'url' => $meta['url'],
        'description' => $meta['description'],
        'author' => ['@type' => 'Person', 'name' => BLOG_SITE['author'], 'url' => $home],
        'publisher' => ['@type' => 'Person', 'name' => BLOG_SITE['author'], 'url' => $home],
    ]);
}

/** The meta description: the summary of a post or of About, cut after a word at 160 characters, else the site's. */
function blog_description(Page $page): string
{
    $summary = blog_is_post($page) || $page->intendedTemplate()->name() === 'about' ? blog_summary($page) : '';
    return $summary === '' ? BLOG_SITE['description'] : Str::excerpt($summary, 160, false);
}

/** The picture for link previews: the cover, on the home page that of the featured photo. */
function blog_share_cover(Page $page): ?File
{
    return ($page->isHomePage() ? blog_picks()->first() : $page)?->cover()->toFile();
}

/** When a page last changed: About's date by hand, else the time of the page's content file. */
function blog_modified(Page $page): int
{
    return $page->lastmod()->isNotEmpty() ? $page->lastmod()->toDate() : $page->modified();
}

/** JSON for a script tag: "<" escaped, so it cannot close the tag. */
function blog_json(array $data): string
{
    return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_PRETTY_PRINT);
}

/**
 * The CSS of the site in one piece, inline in every page, so without
 * comments and with less whitespace. The order counts: a later file
 * overrides an earlier one.
 */
function blog_css(): string
{
    $css = '';
    foreach (['fonts', 'tokens', 'base', 'page', 'exif', 'home', 'article', 'archive'] as $name) {
        $css .= F::read(kirby()->root('assets') . "/css/$name.css");
    }
    // Outside strings: comments go, whitespace shrinks to one space, and
    // none around { } ; and ",". Other spaces stay: one before ":" can be
    // a descendant selector, calc() needs the ones around + and -.
    $string = '"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'';
    $css = preg_replace_callback("!($string)|/\*.*?\*/!s", fn(array $m) => $m[1] ?? '', $css);
    $parts = preg_split("!($string)!s", $css, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $i => $part) {
        if ($i % 2 === 0) {
            $parts[$i] = preg_replace(['!\s+!', '! ?([{};,]) ?!'], [' ', '$1'], $part);
        }
    }
    return trim(implode('', $parts));
}

/**
 * The scripts of a page, inline, in this order. Lists keep their scroll
 * position. Pages with a text (posts, About) get the back link and copy
 * buttons, the archive the back link too. Search asks search.json while
 * typing, as a module.
 */
function blog_scripts(Page $page): array
{
    $template = $page->intendedTemplate()->name();
    $text = !in_array($template, ['home', 'archive', 'search', 'error'], true);
    return array_keys(array_filter([
        'scroll' => in_array($template, ['home', 'archive', 'search'], true),
        'back' => $text || $template === 'archive',
        'fit' => $template === 'home',
        'copy' => $text,
        'search' => $template === 'search',
    ]));
}

/** A script under assets/js, for a script tag. */
function blog_js(string $name): string
{
    return (string) F::read(kirby()->root('assets') . "/js/$name.js");
}

/** URL of a file under assets/ with its time: a changed file gets a new URL. */
function blog_asset(string $path): string
{
    return url('assets/' . $path) . '?v=' . filemtime(kirby()->root('assets') . '/' . $path);
}

/** The name under which a cover moves between pages (view transition). */
function blog_vt_name(Page $post): string
{
    return 'post-' . preg_replace('/[^a-zA-Z0-9-]/', '-', trim((string) parse_url($post->url(), PHP_URL_PATH), '/'));
}

/**
 * A photo across the column: src at 1600 px, the srcset at 600, 1000 and
 * 1600 px, and its width and height at 1600 px for the layout.
 */
function blog_picture(File $file): array
{
    $srcset = array_map(fn(int $width) => blog_wide($file, $width)->url() . " {$width}w", [600, 1000, 1600]);
    // fitWidth() changes its object: a copy, so the file keeps its own size.
    $size = (clone $file->dimensions())->fitWidth(1600);
    return ['src' => blog_wide($file, 1600)->url(), 'srcset' => implode(', ', $srcset), 'width' => $size->width(), 'height' => $size->height()];
}

/** The image $width px wide (never enlarged), WebP at quality 80. */
function blog_wide(File $file, int $width): FileVersion|File
{
    return $file->thumb(['width' => $width, 'format' => 'webp', 'quality' => 80]);
}

/** A square of $edge px, WebP: from the middle, or from the focus point set in the Panel. */
function blog_square(File $file, int $edge, int $quality): FileVersion|File
{
    return $file->thumb(['width' => $edge, 'height' => $edge, 'crop' => true, 'format' => 'webp', 'quality' => $quality]);
}
