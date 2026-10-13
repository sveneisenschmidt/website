<?php

namespace Website\Tests\Integration;

use Website\Tests\Support\TestSite;

/**
 * The files for machines (plugins/blog/feeds.php, config/routes.php):
 * feed and sitemap as Hugo wrote them, robots.txt, llms.txt.
 */
final class FeedTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $cover = $this->upload(__DIR__ . '/../fixtures/exif.jpg', 'cover.jpg');
        $this->post = $this->kirby->impersonate('kirby', fn () => $this->post->update([
            'cover' => [$cover->uuid()->toString()],
            'intro' => "It's the intro.",
            'text' => 'Five more words come here.',
        ]));
    }

    public function testFeedAsHugoWroteIt(): void
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
        // Hugo's Fit "768x768": the fixture is smaller and keeps its size. 9 words: the EXIF rows count too.
        $this->assertStringContainsString(
            '<description><![CDATA[<img src="' . $cover->thumb(['width' => 768, 'height' => 768, 'format' => 'webp', 'quality' => 80])->url()
            . '" width="60" height="40" alt="Test Photo cover image" /><p>It’s the intro.' . "\n"
            . " (<a href=\"$link\" title=\"Continue reading Test Photo\">5 more words</a>)</p>]]></description>",
            $xml
        );
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
            $xml
        );
    }

    public function testRobotsAllowEverythingAndNameTheSitemap(): void
    {
        $this->assertSame("User-agent: *\nAllow: /\n\nSitemap: http://localhost/sitemap.xml\n", $this->kirby->render('robots.txt')->body());
    }

    public function testLlmsTxtFromTheContent(): void
    {
        $this->kirby->impersonate('kirby', fn () => $this->kirby->site()->update(['llms' => "> A personal website.\n\nCrawl it."]));
        $essay = $this->post('essays', 'essay', 'an-essay', 'An Essay');

        $text = $this->kirby->render('llms.txt')->body();

        $this->assertStringStartsWith("# Sven Eisenschmidt - Personal Blog\n\n> A personal website.\n\nCrawl it.\n\n## Essays\n\n- [An Essay]({$essay->url()}): Test intro\n\n## Site\n", $text);
        $this->assertStringContainsString('- [Feed](http://localhost/index.xml)', $text);
    }
}
