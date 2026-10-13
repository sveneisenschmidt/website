<?php

use Kirby\Cms\File;
use Kirby\Cms\FileVersion;
use Kirby\Cms\Page;
use Kirby\Filesystem\F;

/**
 * What the frame of a page needs (snippets/layout.php, snippets/head.php)
 * and the image sizes Hugo's templates asked for.
 */

/** Hugo's .Kind: home, page, section (archive and search) or 404. */
function blog_kind(Page $page): string
{
    return match (true) {
        $page->isHomePage() => 'home',
        $page->isErrorPage() => '404',
        in_array($page->id(), ['archive', 'search'], true) => 'section',
        default => 'page',
    };
}

/** The meta description (head/meta.html): the summary of a post or of About, else the site's. */
function blog_description(Page $page): string
{
    $summary = blog_is_post($page) || $page->intendedTemplate()->name() === 'about' ? blog_summary($page) : '';
    return $summary === '' ? BLOG_SITE['description'] : blog_truncate(ltrim(blog_plain($summary)));
}

/** The picture for link previews: the cover, on the home page that of the featured photo. */
function blog_share_cover(Page $page): ?File
{
    return ($page->isHomePage() ? blog_picks()->first() : $page)?->cover()->toFile();
}

/** Hugo's .Lastmod: About's date by hand, else the time of the page's content file. */
function blog_modified(Page $page): int
{
    return $page->lastmod()->isNotEmpty() ? $page->lastmod()->toDate() : $page->modified();
}

/** JSON for a script tag: "<" escaped, so it cannot close the tag. */
function blog_json(array $data): string
{
    return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_PRETTY_PRINT);
}

/** The CSS of the site in one piece, in Hugo's order (head/css.html). */
function blog_css(): string
{
    $css = '';
    foreach (['fonts', 'tokens', 'base', 'page', 'exif', 'home', 'article', 'archive'] as $name) {
        $css .= F::read(kirby()->root('assets') . "/css/$name.css");
    }
    return $css;
}

/** URL of a file under assets/ with its time: a changed file gets a new URL. */
function blog_asset(string $path): string
{
    return url('assets/' . $path) . '?v=' . filemtime(kirby()->root('assets') . '/' . $path);
}

/** The name under which a cover moves between pages (vt-name.html). */
function blog_vt_name(Page $post): string
{
    return 'post-' . preg_replace('/[^a-zA-Z0-9-]/', '-', trim((string)parse_url($post->url(), PHP_URL_PATH), '/'));
}

/** Hugo's Resize "Nx webp q80". */
function blog_wide(File $file, int $width): FileVersion|File
{
    return $file->thumb(['width' => $width, 'format' => 'webp', 'quality' => 80]);
}

/**
 * Width and height of blog_wide(), from the original: a page never has to
 * create a thumbnail to know its size. Kirby, unlike Hugo, never enlarges.
 */
function blog_wide_size(File $file, int $width): array
{
    $w = min($width, (int)$file->width());
    return [$w, $file->width() > 0 ? (int)round($file->height() * $w / $file->width()) : 0];
}

/** Hugo's Fill "NxN Smart webp qQ": a square from the middle, or from the focus point set in the Panel. */
function blog_square(File $file, int $edge, int $quality): FileVersion|File
{
    return $file->thumb(['width' => $edge, 'height' => $edge, 'crop' => true, 'format' => 'webp', 'quality' => $quality]);
}
