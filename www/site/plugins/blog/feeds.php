<?php

use Kirby\Cms\Page;
use Kirby\Cms\Response;
use Kirby\Toolkit\Xml;

/**
 * The files for machines, from the content: the feed (index.xml), the
 * sitemap, robots.txt and llms.txt.
 */

/** The date format of RSS. */
const BLOG_RSS_DATE = 'D, d M Y H:i:s O';

/** The text of the feed, newest post first. */
function blog_rss(): string
{
    $site = site();
    $posts = collection('posts');
    $home = $site->url() . '/';
    $xml = '<?xml version="1.0" encoding="utf-8" standalone="yes"?>' . "\n"
        . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n"
        . "  <channel>\n"
        . '    <title>' . Xml::encode($site->title()->value()) . "</title>\n"
        . "    <link>$home</link>\n"
        . '    <description>' . Xml::encode(BLOG_SITE['description']) . "</description>\n"
        . "    <generator>Kirby</generator>\n"
        . "    <language>en-us</language>\n"
        . '    <lastBuildDate>' . ($posts->first()?->date()->toDate(BLOG_RSS_DATE) ?? '') . "</lastBuildDate>\n"
        . "    <atom:link href=\"{$home}index.xml\" rel=\"self\" type=\"application/rss+xml\" />\n";
    $frozen = blog_rss_guids();
    foreach ($posts as $post) {
        $link = $post->url() . '?source=rss';
        $xml .= "    <item>\n"
            . '      <title>' . Xml::encode($post->title()->value()) . "</title>\n"
            . "      <link>$link</link>\n"
            . '      <pubDate>' . $post->date()->toDate(BLOG_RSS_DATE) . "</pubDate>\n"
            . '      <guid isPermaLink="true">' . ($frozen[parse_url($post->url(), PHP_URL_PATH)] ?? $post->url()) . "?source=rss</guid>\n"
            . '      <description><![CDATA[' . blog_rss_description($post, $link) . "]]></description>\n"
            . "    </item>\n";
    }
    return $xml . "  </channel>\n</rss>\n";
}

/** A feed item: cover, summary and the number of words after it. */
function blog_rss_description(Page $post, string $link): string
{
    $html = '';
    if ($cover = $post->cover()->toFile()) {
        // fit() changes its object: a copy, so the file keeps its own size.
        $size = (clone $cover->dimensions())->fit(768);
        $html .= '<img src="' . $cover->thumb(['width' => 768, 'height' => 768, 'format' => 'webp', 'quality' => 80])->url()
            . '" width="' . $size->width() . '" height="' . $size->height()
            . '" alt="' . esc($post->title()->value() . ' cover image') . '" />';
    }
    $summary = blog_summary($post);
    $more = blog_words(blog_plain_content($post)) - blog_words($summary);
    $html .= '<p>' . esc($summary);
    if ($more > 0) {
        $html .= ' (<a href="' . $link . '" title="Continue reading ' . esc($post->title()->value()) . '">' . $more . ' more words</a>)';
    }
    return $html . '</p>';
}

/** The home page, About and every post, each with its time, a post with its cover. */
function blog_sitemap(): string
{
    $posts = collection('posts');
    $xml = '<?xml version="1.0" encoding="utf-8" standalone="yes"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n"
        . '  xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
    $newest = $posts->first() ? blog_modified($posts->first()) : null;
    $xml .= '  <url>' . "\n" . '    <loc>' . site()->url() . "/</loc>\n"
        . ($newest ? '    <lastmod>' . date('c', $newest) . "</lastmod>\n" : '') . "  </url>\n";
    if ($about = page('about')) {
        $xml .= "  <url>\n    <loc>" . $about->url() . "</loc>\n    <lastmod>" . date('c', blog_modified($about)) . "</lastmod>\n  </url>\n";
    }
    foreach ($posts as $post) {
        $xml .= "  <url>\n    <loc>" . $post->url() . "</loc>\n"
            . '    <lastmod>' . date('c', blog_modified($post)) . "</lastmod>\n";
        // The cover as image entry, in the size the page shows.
        if ($cover = $post->cover()->toFile()) {
            $xml .= "    <image:image>\n      <image:loc>" . blog_wide($cover, 1600)->url() . "</image:loc>\n    </image:image>\n";
        }
        $xml .= "  </url>\n";
    }
    return $xml . "</urlset>\n";
}

/** robots.txt: every path may be crawled, and it names the sitemap. */
function blog_robots(): string
{
    return "User-agent: *\nAllow: /\n\nSitemap: " . url('sitemap.xml') . "\n";
}

/**
 * Summary for language models (llmstxt.org): the introduction from
 * Website › Beiträge, then essays and notes with their summary, then
 * the pages of the site.
 */
function blog_llms(): string
{
    $site = site();
    $lines = ['# ' . $site->title()->value(), '', trim((string)$site->llms()->value())];
    foreach (['essays' => 'Essays', 'notes' => 'Notes'] as $section => $heading) {
        $posts = collection('posts')->filter(fn (Page $p) => $p->parent()->id() === $section);
        if ($posts->isNotEmpty()) {
            array_push($lines, '', "## $heading", '');
        }
        foreach ($posts as $post) {
            $lines[] = '- [' . $post->title()->value() . '](' . $post->url() . '): ' . blog_description($post);
        }
    }
    $url = $site->url();
    array_push($lines, '', '## Site', '',
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
 */
function blog_machine_file(string $name, string $type, Closure $build): Response
{
    $cache = kirby()->cache('pages');
    $entry = $cache->get($name);
    // An entry with only the text has no build time: it is built again.
    if (!is_array($entry)) {
        $entry = ['text' => $build(), 'time' => time()];
        $cache->set($name, $entry);
    }
    $headers = ['Last-Modified' => gmdate('D, d M Y H:i:s', $entry['time']) . ' GMT'];
    $since = strtotime((string)kirby()->request()->header('If-Modified-Since'));
    if ($since !== false && $since >= $entry['time']) {
        return new Response('', $type, 304, $headers);
    }
    return new Response($entry['text'], $type, 200, $headers);
}

/**
 * The GUIDs that never change, so a feed reader shows no post twice: path
 * of a post => the old URL of each redirect with rss: keep. A later
 * redirect wins.
 */
function blog_rss_guids(): array
{
    $guids = [];
    foreach (blog_redirects() as $redirect) {
        if (($redirect['rss'] ?? '') === 'keep') {
            $guids[$redirect['to']] = site()->url() . $redirect['from'];
        }
    }
    return $guids;
}
