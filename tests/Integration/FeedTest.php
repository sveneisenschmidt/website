<?php

namespace Website\Tests\Integration;

use Website\Tests\Support\TestSite;

/**
 * The files for machines (plugins/blog/feeds.php, config/routes.php):
 * feed, sitemap, robots.txt, llms.txt.
 */
final class FeedTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $cover = $this->upload(__DIR__ . '/../fixtures/exif.jpg', 'cover.jpg');
        $this->post = $this->kirby->impersonate('kirby', fn() => $this->post->update([
            'cover' => [$cover->uuid()->toString()],
            'intro' => "It's the intro.",
            'text' => "(image: cover.jpg)\n\nFive more words come here.",
        ]));
    }

    /** HEAD answers 200, as for every page. Without 'method' a route answers HEAD with 404. */
    public function testTheFilesForMachinesAnswerHead(): void
    {
        foreach (['index.xml', 'sitemap.xml', 'robots.txt', 'llms.txt'] as $path) {
            $this->assertSame(200, $this->kirby->render($path, 'HEAD')?->code(), $path);
        }
    }

    public function testFeedWithChannelAndItems(): void
    {
        $response = $this->kirby->render('index.xml');
        $xml = $response->body();
        $link = $this->post->url() . '?source=rss';
        $cover = $this->post->cover()->toFile();

        $this->assertSame('application/rss+xml', $response->type());
        $this->assertStringContainsString("<title>Sven Eisenschmidt - Personal Blog</title>\n    <link>http://localhost/</link>", $xml);
        $this->assertStringContainsString('<lastBuildDate>Wed, 07 Oct 2026 20:00:00 +0200</lastBuildDate>', $xml);
        $this->assertStringContainsString('<atom:link href="http://localhost/index.xml" rel="self" type="application/rss+xml" />', $xml);
        $this->assertStringContainsString("<link>$link</link>\n      <pubDate>Wed, 07 Oct 2026 20:00:00 +0200</pubDate>\n      <guid isPermaLink=\"true\">$link</guid>", $xml);
        // The cover fits into 768 x 768: the fixture is smaller and keeps its size.
        // The EXIF rows of the figure in the text are no words.
        $this->assertStringContainsString(
            '<description><![CDATA[<img src="' . $cover->thumb(['width' => 768, 'height' => 768, 'format' => 'webp', 'quality' => 80])->url()
            . '" width="60" height="40" alt="Test Photo cover image" /><p>It’s the intro.'
            . " (<a href=\"$link\" title=\"Continue reading Test Photo\">5 more words</a>)</p>]]></description>",
            $xml,
        );
    }

    public function testFeedAndSitemapAnswerIfModifiedSince(): void
    {
        foreach (['index.xml', 'sitemap.xml'] as $path) {
            $first = $this->kirby->render($path);
            $modified = (string) $first->header('Last-Modified');
            $request = fn(string $since) => TestSite::app($this->root, $this->options, ['server' => ['HTTP_IF_MODIFIED_SINCE' => $since]])->render($path);

            $this->assertMatchesRegularExpression('/^\w{3}, \d{2} \w{3} \d{4} \d{2}:\d{2}:\d{2} GMT$/', $modified, $path);
            $this->assertSame(200, $first->code(), $path);

            $unchanged = $request($modified);
            $this->assertSame(304, $unchanged->code(), $path);
            $this->assertSame('', $unchanged->body(), $path);
            $this->assertSame($modified, $unchanged->header('Last-Modified'), $path);

            $older = $request(gmdate('D, d M Y H:i:s', strtotime($modified) - 60) . ' GMT');
            $this->assertSame(200, $older->code(), $path);
            $this->assertSame($first->body(), $older->body(), $path);
        }
    }

    public function testSitemapWithTheTimeOfTheContentFileAndTheCover(): void
    {
        touch($this->post->root() . '/photo.txt', strtotime('2026-10-08 09:30:00'));
        $kirby = TestSite::app($this->root, $this->options);
        $post = $kirby->page('photos/test-photo');

        $xml = $kirby->render('sitemap.xml')->body();

        $this->assertStringContainsString("<url>\n    <loc>http://localhost/</loc>\n    <lastmod>2026-10-08T09:30:00+02:00</lastmod>\n  </url>", $xml);
        $this->assertStringContainsString(
            "<loc>{$post->url()}</loc>\n    <lastmod>2026-10-08T09:30:00+02:00</lastmod>\n    <image:image>\n      <image:loc>"
            . blog_wide($post->cover()->toFile(), 1600)->url() . '</image:loc>',
            $xml,
        );
    }

    public function testSitemapHasAboutWithItsDate(): void
    {
        $this->kirby->impersonate('kirby', fn() => $this->kirby->site()->createChild([
            'slug' => 'about',
            'template' => 'about',
            'draft' => false,
            'content' => ['title' => 'About', 'lastmod' => '2026-09-12'],
        ]));

        $xml = $this->kirby->render('sitemap.xml')->body();

        $this->assertStringContainsString("<url>\n    <loc>http://localhost/about/</loc>\n    <lastmod>2026-09-12T00:00:00+02:00</lastmod>\n  </url>", $xml);
    }

    public function testRobotsAllowEverythingAndNameTheSitemap(): void
    {
        $this->assertSame("User-agent: *\nAllow: /\n\nSitemap: http://localhost/sitemap.xml\n", $this->kirby->render('robots.txt')->body());
    }

    public function testLlmsTxtFromTheContent(): void
    {
        $this->kirby->impersonate('kirby', fn() => $this->kirby->site()->update(['llms' => "> A personal website.\n\nCrawl it."]));
        $essay = $this->post('essays', 'essay', 'an-essay', 'An Essay');

        $text = $this->kirby->render('llms.txt')->body();

        $this->assertStringStartsWith("# Sven Eisenschmidt - Personal Blog\n\n> A personal website.\n\nCrawl it.\n\n## Essays\n\n- [An Essay]({$essay->url()}): Test intro\n\n## Site\n", $text);
        $this->assertStringContainsString('- [Feed](http://localhost/index.xml)', $text);
        $this->assertSame($text, $this->kirby->cache('pages')->get('llms.txt'), 'in the page cache');
    }
}
