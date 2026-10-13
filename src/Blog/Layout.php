<?php

namespace Website\Blog;

use Kirby\Cms\Page;
use Kirby\Filesystem\F;
use Website\Models\PostPage;
use Website\Models\SitePage;

/**
 * What the frame of a page needs (snippets/layout.php, snippets/head.php):
 * meta tags, JSON-LD, the CSS and the scripts inline, asset URLs.
 */
final class Layout
{
    /**
     * What the head says about a page: title, description, URL, whether it is
     * an article (a post or About) and the picture for link previews.
     *
     * @return array{title: string, description: string, article: bool, url: string, image: ?string}
     */
    public static function meta(SitePage $page): array
    {
        $site = $page->site()->title()->value();
        return [
            'title' => $page->isHomePage() ? $site : $page->title()->value() . ' · ' . $site,
            'description' => $page->metaDescription(),
            'article' => $page->isArticle(),
            'url' => $page->url(),
            'image' => $page->shareImage(),
        ];
    }

    /**
     * JSON-LD: the person on the home page, the article on a post, else null.
     * Job title and employer come from About; an empty field leaves its key out.
     *
     * @param array{title: string, description: string, article: bool, url: string, image: ?string} $meta
     * @return array<string, mixed>|null
     */
    public static function jsonLd(SitePage $page, array $meta): ?array
    {
        $home = $page->site()->url() . '/';
        if ($page->isHomePage()) {
            $about = page('about');
            $employer = (string) $about?->employer()->value();
            return array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'Person',
                'name' => Site::AUTHOR,
                'url' => $home,
                'jobTitle' => (string) $about?->jobtitle()->value(),
                'worksFor' => $employer === '' ? null : array_filter(['@type' => 'Organization', 'name' => $employer, 'url' => (string) $about->employerurl()->value()]),
                'sameAs' => [Site::GITHUB_URL, Site::LINKEDIN_URL, Site::STRAVA_URL],
            ]);
        }
        if (!$page instanceof PostPage) {
            return null;
        }
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $page->title()->value(),
            'datePublished' => $page->date()->toDate('c'),
            'dateModified' => date('c', $page->lastModified()),
            'image' => $meta['image'],
            'url' => $meta['url'],
            'description' => $meta['description'],
            'author' => ['@type' => 'Person', 'name' => Site::AUTHOR, 'url' => $home],
            'publisher' => ['@type' => 'Person', 'name' => Site::AUTHOR, 'url' => $home],
        ]);
    }

    /**
     * JSON for a script tag: "<" escaped, so it cannot close the tag.
     *
     * @param array<string, mixed> $data
     */
    public static function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_PRETTY_PRINT);
    }

    /**
     * The CSS of the site in one piece, inline in every page, so without
     * comments and with less whitespace. The order counts: a later file
     * overrides an earlier one.
     */
    public static function css(): string
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
     *
     * @return list<string>
     */
    public static function scripts(Page $page): array
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
    public static function js(string $name): string
    {
        return (string) F::read(kirby()->root('assets') . "/js/$name.js");
    }

    /** URL of a file under assets/ with its time: a changed file gets a new URL. */
    public static function asset(string $path): string
    {
        return url('assets/' . $path) . '?v=' . filemtime(kirby()->root('assets') . '/' . $path);
    }
}
