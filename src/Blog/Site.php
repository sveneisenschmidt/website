<?php

namespace Website\Blog;

use Kirby\Cms\ModelWithContent;

/**
 * Texts about the site and its author for the page header, the meta tags,
 * the feed and JSON-LD (the data for search engines in a script element).
 * They come from the content: the tab "Angaben" of the site in the Panel,
 * and the sidebar of the page About. This public repository holds no
 * personal data. An empty field gives an empty string or an empty list,
 * and the callers then leave out the tag or the key.
 */
final class Site
{
    /** The name at the top of every page; the site title when the field is empty. */
    public static function heading(): string
    {
        return self::field(site(), 'heading') ?: site()->title()->value();
    }

    /** The meta description of a page without a summary, and the description of the feed. */
    public static function description(): string
    {
        return self::field(site(), 'description');
    }

    public static function keywords(): string
    {
        return self::field(site(), 'keywords');
    }

    /** The author's name in the meta tags and in JSON-LD. */
    public static function author(): string
    {
        return self::field(page('about'), 'author');
    }

    /** The address of the link "Leave me a message" under posts and About. */
    public static function email(): string
    {
        return self::field(page('about'), 'email');
    }

    /**
     * The author's profiles on other sites, for "sameAs" in JSON-LD.
     *
     * @return list<string>
     */
    public static function profiles(): array
    {
        $about = page('about');
        if ($about === null) {
            return [];
        }
        $urls = [];
        foreach ($about->profiles()->toStructure() as $profile) {
            $url = (string) $profile->content()->get('url')->value();
            if ($url !== '') {
                $urls[] = $url;
            }
        }
        return $urls;
    }

    public static function jobTitle(): string
    {
        return self::field(page('about'), 'jobtitle');
    }

    public static function employer(): string
    {
        return self::field(page('about'), 'employer');
    }

    public static function employerUrl(): string
    {
        return self::field(page('about'), 'employerurl');
    }

    private static function field(?ModelWithContent $model, string $key): string
    {
        return $model === null ? '' : (string) $model->content()->get($key)->value();
    }
}
