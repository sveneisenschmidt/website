<?php

namespace Website\Tests\Integration;

/**
 * A post in the frame of the site: head, article, timeline with the
 * neighbours.
 */
final class PostPageTest extends IntegrationTestCase
{
    public function testHead(): void
    {
        $html = $this->post->render();
        $url = $this->post->url();

        $this->assertStringContainsString('<title>Test Photo · Sven Eisenschmidt - Personal Blog</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="Test intro">', $html);
        $this->assertStringContainsString('<meta property="og:type" content="article">', $html);
        $this->assertStringContainsString('<meta property="article:published_time" content="2026-10-07T20:00:00+02:00">', $html);
        $this->assertStringContainsString("<link rel=\"canonical\" href=\"$url\">", $html);
        $this->assertStringContainsString('"@type": "Article"', $html);
        $this->assertStringContainsString('<body class="photo">', $html);
        $this->assertStringContainsString('<p class="masthead"><a href="/">Sven Eisenschmidt</a></p>', $html);
        $this->assertStringNotContainsString('pop.eisenschmidt.website', $html);
    }

    public function testCssAndTheScriptsOfAPostInline(): void
    {
        $html = $this->post->render();

        $this->assertStringContainsString('font-family: "Hanken Grotesk"', $html);
        $this->assertStringContainsString('a[data-back]', $html, 'back.js');
        $this->assertStringContainsString('copy-button', $html, 'copy.js');
        $this->assertStringNotContainsString('stream-scroll', $html, 'scroll.js is for lists');
        $this->assertStringNotContainsString('leaflet', $html, 'Leaflet only with a map');
    }

    public function testArticle(): void
    {
        $html = $this->post->render();

        $this->assertStringContainsString('<a href="/archive/photos/">photo</a>', $html);
        $this->assertStringContainsString('<a href="/archive/"><time datetime="2026-10-07">October 7, 2026</time></a>', $html);
        $this->assertStringContainsString('<div class="body">' . "\n<p>Test intro</p>", $html);
        $this->assertStringContainsString('<a href="mailto:sven.eisenschmidt@gmail.com?subject=Re%3A%20Test%20Photo">Leave me a message</a>', $html);
    }

    public function testTimelineShowsTheNeighbours(): void
    {
        $older = $this->kirby->impersonate('kirby', fn() => $this->post('notes', 'note', 'older', 'Older')->update(['date' => '2026-10-01 10:00:00']));
        $newer = $this->kirby->impersonate('kirby', fn() => $this->post('essays', 'essay', 'newer', 'Newer')->update(['date' => '2026-10-09 10:00:00']));

        $html = $this->kirby->page('photos/test-photo')->render();

        $this->assertStringContainsString('<section class="writing writing-aside" aria-label="Posts">', $html);
        $this->assertStringContainsString('<a href="' . $newer->url() . '"><span class="writing-label">Next:</span> Newer</a>', $html);
        $this->assertStringContainsString('<a href="' . $older->url() . '"><span class="writing-label">Previous:</span> Older</a>', $html);
        $this->assertStringContainsString('&middot; Essay, 1 min', $html);
        $this->assertStringNotContainsString('<span class="stream-count">', $html);
    }
}
