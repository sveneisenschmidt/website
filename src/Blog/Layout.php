<?php

namespace Website\Blog;

use Kirby\Cms\Page;
use Kirby\Filesystem\F;
use Website\Models\PostPage;
use Website\Models\SitePage;

/**
 * The data for the HTML around the content of every page, which
 * snippets/layout.php and snippets/head.php render: meta tags, JSON-LD
 * (structured data for search engines), the inline CSS and scripts, and
 * asset URLs.
 */
final class Layout
{
    /**
     * The values for the meta tags of a page: title, description, URL,
     * whether it is an article (a post or About), and the image for link
     * previews.
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
     * The JSON-LD data (schema.org): a Person on the home page, an Article on
     * a post, null on every other page. Job title and employer come from the
     * fields of About. array_filter() drops a key whose value is empty.
     *
     * @param array{title: string, description: string, article: bool, url: string, image: ?string} $meta
     * @return array<string, mixed>|null
     */
    public static function jsonLd(SitePage $page, array $meta): ?array
    {
        $home = $page->site()->url() . '/';
        if ($page->isHomePage()) {
            $employer = Site::employer();
            return array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'Person',
                'name' => Site::author(),
                'url' => $home,
                'jobTitle' => Site::jobTitle(),
                'worksFor' => $employer === '' ? null : array_filter(['@type' => 'Organization', 'name' => $employer, 'url' => Site::employerUrl()]),
                'sameAs' => Site::profiles(),
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
            'author' => array_filter(['@type' => 'Person', 'name' => Site::author(), 'url' => $home]),
            'publisher' => array_filter(['@type' => 'Person', 'name' => Site::author(), 'url' => $home]),
        ]);
    }

    /**
     * JSON for an inline script element. JSON_HEX_TAG escapes "<" and ">", so
     * a value that contains "</script>" cannot end the element.
     *
     * @param array<string, mixed> $data
     */
    public static function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_PRETTY_PRINT);
    }

    /**
     * All CSS files joined into one string, which snippets/head.php puts
     * inline into every page. Every page contains the full CSS, so comments
     * and most whitespace are removed. The order of the files matters: a
     * rule in a later file overrides the same rule in an earlier one.
     */
    public static function css(): string
    {
        $css = '';
        foreach (['fonts', 'tokens', 'base', 'page', 'exif', 'home', 'article', 'archive'] as $name) {
            $css .= F::read(kirby()->root('assets') . "/css/$name.css");
        }
        // Outside quoted strings, comments are removed, each run of whitespace
        // becomes one space, and the spaces around { } ; and "," are removed.
        // Other spaces must stay. A space before ":" can be a descendant
        // combinator (`a :hover`), and calc() requires the spaces around + and -.
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
     * The names of the scripts under assets/js that snippets/head.php puts
     * inline into a page, in this order. scroll.js keeps the scroll position
     * when the visitor changes the filter. Pages with a text (posts, About,
     * default) get back.js and copy.js, the archive gets back.js too. fit.js
     * shortens the timeline on the home page to the height of the pictures.
     * search.js loads the results while the visitor types.
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

    /** The content of assets/js/$name.js, for an inline script element. */
    public static function js(string $name): string
    {
        return (string) F::read(kirby()->root('assets') . "/js/$name.js");
    }

    /**
     * The URL of a file under assets/ with its modification time as ?v=. A
     * changed file gets a new URL, so the one-year browser cache that
     * .htaccess sets never serves an old copy.
     */
    public static function asset(string $path): string
    {
        return url('assets/' . $path) . '?v=' . filemtime(kirby()->root('assets') . '/' . $path);
    }
}
