<?php

namespace Website\Blog;

use Closure;
use Kirby\Cms\Page;
use Kirby\Cms\Response;
use Kirby\Toolkit\Xml;
use Website\Models\PostPage;
use Website\Models\SitePage;

/**
 * The files for machines, from the content: the feed (index.xml), the
 * sitemap, robots.txt and llms.txt. config/routes.php serves them.
 */
final class Feeds
{
    /** The date format of RSS. */
    private const RSS_DATE = 'D, d M Y H:i:s O';

    /** The text of the feed, newest post first. */
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
            . '    <description>' . Xml::encode(Site::DESCRIPTION) . "</description>\n"
            . "    <generator>Kirby</generator>\n"
            . "    <language>en-us</language>\n"
            . '    <lastBuildDate>' . ($posts->first()?->date()->toDate(self::RSS_DATE) ?? '') . "</lastBuildDate>\n"
            . "    <atom:link href=\"{$home}index.xml\" rel=\"self\" type=\"application/rss+xml\" />\n";
        $frozen = self::guids();
        foreach ($posts as $post) {
            $link = $post->url() . '?source=rss';
            $xml .= "    <item>\n"
                . '      <title>' . Xml::encode($post->title()->value()) . "</title>\n"
                . "      <link>$link</link>\n"
                . '      <pubDate>' . $post->date()->toDate(self::RSS_DATE) . "</pubDate>\n"
                . '      <guid isPermaLink="true">' . ($frozen[parse_url($post->url(), PHP_URL_PATH)] ?? $post->url()) . "?source=rss</guid>\n"
                . '      <description><![CDATA[' . self::rssDescription($post, $link) . "]]></description>\n"
                . "    </item>\n";
        }
        return $xml . "  </channel>\n</rss>\n";
    }

    /** A feed item: cover, summary and the number of words after it. */
    private static function rssDescription(PostPage $post, string $link): string
    {
        $html = '';
        if ($cover = $post->coverImage()) {
            // fit() changes its object: a copy, so the file keeps its own size.
            $size = (clone $cover->dimensions())->fit(768);
            $html .= '<img src="' . $cover->thumb(['width' => 768, 'height' => 768, 'format' => 'webp', 'quality' => 80])->url()
                . '" width="' . $size->width() . '" height="' . $size->height()
                . '" alt="' . esc($post->title()->value() . ' cover image') . '" />';
        }
        $summary = $post->summary();
        $more = $post->wordCount() - Text::words($summary);
        $html .= '<p>' . esc($summary);
        if ($more > 0) {
            $html .= ' (<a href="' . $link . '" title="Continue reading ' . esc($post->title()->value()) . '">' . $more . ' more words</a>)';
        }
        return $html . '</p>';
    }

    /**
     * The GUIDs that never change, so a feed reader shows no post twice: path
     * of a post => the old URL of each redirect with rss: keep. A later
     * redirect wins.
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

    /** The home page, About and every post, each with its time, a post with its cover. */
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
            // The cover as image entry, in the size the page shows.
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
     * Summary for language models (llmstxt.org): the introduction from
     * Website › Beiträge, then essays and notes with their summary, then
     * the pages of the site.
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
     * A file for machines from the page cache, which Kirby clears on every
     * change: built by $build when missing, its build time as Last-Modified.
     * A reader that has this build gets 304 without a body.
     *
     * @param Closure(): string $build
     */
    public static function machineFile(string $name, string $type, Closure $build): Response
    {
        $cache = kirby()->cache('pages');
        $entry = $cache->get($name);
        // An entry with only the text has no build time: it is built again.
        if (!is_array($entry)) {
            $entry = ['text' => $build(), 'time' => time()];
            $cache->set($name, $entry);
        }
        $headers = ['Last-Modified' => gmdate('D, d M Y H:i:s', $entry['time']) . ' GMT'];
        $since = strtotime((string) kirby()->request()->header('If-Modified-Since'));
        // A date in the future comes from a wrong clock and does not count (RFC 9110).
        if ($since !== false && $since >= $entry['time'] && $since <= time()) {
            return new Response('', $type, 304, $headers);
        }
        return new Response($entry['text'], $type, 200, $headers);
    }
}
