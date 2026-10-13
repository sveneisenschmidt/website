<?php

use Kirby\Cms\Page;
use Kirby\Toolkit\Xml;

/**
 * The files for machines, from the content: the feed as Hugo's
 * home.rss.xml, the sitemap as sitemap.xml, robots.txt and llms.txt.
 */

/** The date format of RSS. */
const BLOG_RSS_DATE = 'D, d M Y H:i:s O';

/** The text of the feed, newest post first. */
function blog_rss(): string
{
    $site = site();
    $posts = blog_posts();
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

/** Cover, summary and the number of words after it, as home.rss.xml wrote them. */
function blog_rss_description(Page $post, string $link): string
{
    $html = '';
    if ($cover = $post->cover()->toFile()) {
        $w = (int)$cover->width();
        $h = (int)$cover->height();
        $scale = min(1, 768 / max($w, $h, 1));
        $html .= '<img src="' . $cover->thumb(['width' => 768, 'height' => 768, 'format' => 'webp', 'quality' => 80])->url()
            . '" width="' . (int)round($w * $scale) . '" height="' . (int)round($h * $scale)
            . '" alt="' . esc($post->title()->value() . ' cover image') . '" />';
    }
    $summary = blog_summary($post);
    $more = blog_words(blog_content($post)) - blog_words($summary);
    $html .= '<p>' . esc(html_entity_decode(blog_plain($summary), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($more > 0) {
        $html .= ' (<a href="' . $link . '" title="Continue reading ' . esc($post->title()->value()) . '">' . $more . ' more words</a>)';
    }
    return $html . '</p>';
}

/** The home page and every post, each with its time and its cover. */
function blog_sitemap(): string
{
    $posts = blog_posts();
    $xml = '<?xml version="1.0" encoding="utf-8" standalone="yes"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n"
        . '  xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
    $newest = $posts->first() ? blog_modified($posts->first()) : null;
    $xml .= '  <url>' . "\n" . '    <loc>' . site()->url() . "/</loc>\n"
        . ($newest ? '    <lastmod>' . date('c', $newest) . "</lastmod>\n" : '') . "  </url>\n";
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

/** Until the site is public (env.php: 'public' => true) search engines stay away. */
function blog_robots(): string
{
    return option('public') === true
        ? "User-agent: *\nAllow: /\n\nSitemap: " . url('sitemap.xml') . "\n"
        : "User-agent: *\nDisallow: /\n";
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
        $posts = blog_posts()->filter(fn (Page $p) => $p->parent()->id() === $section);
        if ($posts->isNotEmpty()) {
            array_push($lines, '', "## $heading", '');
        }
        foreach ($posts as $post) {
            $summary = html_entity_decode(trim(preg_replace('/\s+/u', ' ', blog_description($post))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $lines[] = '- [' . $post->title()->value() . '](' . $post->url() . '): ' . $summary;
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
 * The GUIDs that stay as they were (home.rss.xml): path of a post => the
 * old URL of each redirect with rss: keep. A later redirect wins.
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
