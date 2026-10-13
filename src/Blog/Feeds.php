<?php

namespace Website\Blog;

use Closure;
use Kirby\Cms\Page;
use Kirby\Cms\Response;
use Kirby\Toolkit\Xml;
use Website\Models\PostPage;
use Website\Models\SitePage;

/**
 * Builds the files that programs read: the RSS feed (/index.xml), the
 * sitemap (/sitemap.xml), /robots.txt and /llms.txt. config/routes.php
 * serves them.
 */
final class Feeds
{
    /** The RFC 822 date format, which RSS 2.0 requires. */
    private const RSS_DATE = 'D, d M Y H:i:s O';

    /** The RSS feed with every published post, newest first. */
    public static function rss(): string
    {
        $site = site();
        $posts = Posts::all();
        $home = $site->url() . '/';
        $xml = '<?xml version="1.0" encoding="utf-8" standalone="yes"?>' . "\n"
            . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n"
            . "  <channel>\n"
            . '    <title>' . Xml::encode($site->title()->value()) . "</title>\n"
            . "    <link>$home</link>\n"
            . '    <description>' . Xml::encode(Site::description()) . "</description>\n"
            . "    <generator>Kirby</generator>\n"
            . "    <language>en-us</language>\n"
            . '    <lastBuildDate>' . ($posts->first()?->date()->toDate(self::RSS_DATE) ?? '') . "</lastBuildDate>\n"
            . "    <atom:link href=\"{$home}index.xml\" rel=\"self\" type=\"application/rss+xml\" />\n";
        $frozen = self::guids();
        foreach ($posts as $post) {
            // Feed readers rarely send a Referer header. With ?source=rss,
            // Website\Analytics\Counter counts the visit as coming from the feed.
            $link = $post->url() . '?source=rss';
            $xml .= "    <item>\n"
                . '      <title>' . Xml::encode($post->title()->value()) . "</title>\n"
                . "      <link>$link</link>\n"
                . '      <pubDate>' . $post->date()->toDate(self::RSS_DATE) . "</pubDate>\n"
                . '      <guid isPermaLink="true">' . Xml::encode(($frozen[parse_url($post->url(), PHP_URL_PATH)] ?? $post->url()) . '?source=rss') . "</guid>\n"
                . '      <description><![CDATA[' . self::rssDescription($post, $link) . "]]></description>\n"
                . "    </item>\n";
        }
        return $xml . "  </channel>\n</rss>\n";
    }

    /**
     * The HTML of a feed item: the cover, the summary, and a link that names
     * how many words the post has beyond the summary.
     */
    private static function rssDescription(PostPage $post, string $link): string
    {
        $cover = null;
        if ($file = $post->coverImage()) {
            // fit() changes the object it is called on, and the file keeps its
            // dimensions object for later calls. The clone keeps the file's size intact.
            $size = (clone $file->dimensions())->fit(768);
            $cover = ['url' => $file->thumb(['width' => 768, 'height' => 768, 'format' => 'webp', 'quality' => 80])->url(), 'width' => $size->width(), 'height' => $size->height()];
        }
        $summary = $post->summary();
        return rtrim(snippet('feed-item', [
            'post' => $post,
            'cover' => $cover,
            'summary' => $summary,
            'more' => $post->wordCount() - Text::words($summary),
            'link' => $link,
        ], true), "\n");
    }

    /**
     * A feed reader identifies an item by its GUID and shows an item with a
     * new GUID as a new post. When a post moves to a new URL, the redirect
     * from its old URL has `rss: keep`, and the feed keeps that old URL as
     * the GUID. Returns new path => old URL. Of two redirects to the same
     * path, the later one wins.
     *
     * @return array<string, string>
     */
    private static function guids(): array
    {
        $guids = [];
        foreach (Redirects::all() as $redirect) {
            if (($redirect['rss'] ?? '') === 'keep') {
                $guids[$redirect['to']] = site()->url() . $redirect['from'];
            }
        }
        return $guids;
    }

    /** The sitemap: the home page, About and every post, each with the time of its last change. A post also names its cover image. */
    public static function sitemap(): string
    {
        $posts = Posts::all();
        $xml = '<?xml version="1.0" encoding="utf-8" standalone="yes"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n"
            . '  xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
        $newest = $posts->first()?->lastModified();
        $xml .= '  <url>' . "\n" . '    <loc>' . site()->url() . "/</loc>\n"
            . ($newest ? '    <lastmod>' . date('c', $newest) . "</lastmod>\n" : '') . "  </url>\n";
        if (($about = page('about')) instanceof SitePage) {
            $xml .= "  <url>\n    <loc>" . $about->url() . "</loc>\n    <lastmod>" . date('c', $about->lastModified()) . "</lastmod>\n  </url>\n";
        }
        foreach ($posts as $post) {
            $xml .= "  <url>\n    <loc>" . $post->url() . "</loc>\n"
                . '    <lastmod>' . date('c', $post->lastModified()) . "</lastmod>\n";
            // The 1600 px version, the same image file that the post page shows.
            if ($cover = $post->coverImage()) {
                $xml .= "    <image:image>\n      <image:loc>" . Images::wide($cover, 1600)->url() . "</image:loc>\n    </image:image>\n";
            }
            $xml .= "  </url>\n";
        }
        return $xml . "</urlset>\n";
    }

    /** robots.txt: every path may be crawled, and it names the sitemap. */
    public static function robots(): string
    {
        return "User-agent: *\nAllow: /\n\nSitemap: " . url('sitemap.xml') . "\n";
    }

    /**
     * The summary of the site for language models (format of llmstxt.org):
     * the introduction from the site field `llms` (Panel: Website ›
     * Beiträge), then essays and notes with their summaries, then the pages
     * of the site.
     */
    public static function llms(): string
    {
        $site = site();
        $lines = ['# ' . $site->title()->value(), '', trim((string) $site->llms()->value())];
        foreach (['essays' => 'Essays', 'notes' => 'Notes'] as $section => $heading) {
            $posts = Posts::all()->filter(fn(Page $p) => $p->parent()->id() === $section);
            if ($posts->isNotEmpty()) {
                array_push($lines, '', "## $heading", '');
            }
            foreach ($posts as $post) {
                $lines[] = '- [' . $post->title()->value() . '](' . $post->url() . '): ' . $post->metaDescription();
            }
        }
        $url = $site->url();
        array_push(
            $lines,
            '',
            '## Site',
            '',
            "- [Homepage]($url/)",
            "- [Archive]($url/archive/): All posts by month, filterable by type (photos, trips, notes, essays).",
            "- [About]($url/about/): Bio, full project list, blogroll, site statistics.",
            "- [Feed]($url/index.xml)",
            "- [Sitemap]($url/sitemap.xml)",
        );
        return implode("\n", $lines) . "\n";
    }

    /**
     * Serves a generated file from Kirby's page cache. Kirby empties that
     * cache on every content change, and $build then builds the file again.
     * The build time is sent as Last-Modified. A client that sends
     * If-Modified-Since with that time or later gets 304 without a body.
     *
     * @param Closure(): string $build
     */
    public static function machineFile(string $name, string $type, Closure $build): Response
    {
        $cache = kirby()->cache('pages');
        $entry = $cache->get($name);
        // A missing entry is null. An entry that holds only the text has no
        // build time. Both are built again.
        if (!is_array($entry)) {
            $entry = ['text' => $build(), 'time' => time()];
            $cache->set($name, $entry);
        }
        $headers = ['Last-Modified' => gmdate('D, d M Y H:i:s', $entry['time']) . ' GMT'];
        $since = strtotime((string) kirby()->request()->header('If-Modified-Since'));
        // RFC 9110 says to ignore an If-Modified-Since date later than the
        // server's time. Such a date comes from a wrong clock.
        if ($since !== false && $since >= $entry['time'] && $since <= time()) {
            return new Response('', $type, 304, $headers);
        }
        return new Response($entry['text'], $type, 200, $headers);
    }
}
