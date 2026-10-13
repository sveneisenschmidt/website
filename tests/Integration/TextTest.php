<?php

namespace Website\Tests\Integration;

/**
 * plugins/blog/text.php: Markdown, summary, plain text, words and
 * truncation as Hugo made them.
 */
final class TextTest extends IntegrationTestCase
{
    public function testMarkdownAsHugoRenderedIt(): void
    {
        $html = blog_html("A line\nand the next -- \"quoted\" it's... --- done", $this->post);

        // No <br> for a line break, typography as Goldmark's typographer.
        $this->assertSame("<p>A line\nand the next &ndash; &ldquo;quoted&rdquo; it&rsquo;s&hellip; &mdash; done</p>", $html);
    }

    public function testHeadingsGetAnIdAndAnAnchor(): void
    {
        $html = blog_html("## Fox & Wagtail\n\n## Fox & Wagtail\n\n### Über_uns 2", $this->post);

        $this->assertStringContainsString("<h2 id=\"fox--wagtail\">Fox &amp; Wagtail<a class=\"heading-anchor\" href=\"#fox--wagtail\">#</a>\n</h2>", $html);
        $this->assertStringContainsString('<h2 id="fox--wagtail-1">', $html);
        $this->assertStringContainsString('<h3 id="über_uns-2">', $html);
    }

    public function testPlainTextAndWordsAsHugo(): void
    {
        $html = "<p>One <em>two</em></p>\n<p>three</p>\n<div class=\"map\"><script type=\"application/json\">{\"a\":1}</script>\n</div>";

        $this->assertSame("One two\nthree\n", blog_plain($html));
        $this->assertSame(3, blog_words($html));
        // Entities stay, as in Hugo: the feed decodes them, meta tags keep them.
        $this->assertSame("It&rsquo;s\n", blog_plain('<p>It&rsquo;s</p>'));
    }

    public function testTruncateCutsAfterAWord(): void
    {
        $this->assertSame('Short text', blog_truncate('Short text'));
        $this->assertSame(str_repeat('word ', 31) . 'word …', blog_truncate(str_repeat('word ', 40)));
    }

    public function testSummaryIsTheIntroOrTheFirstParagraphsUpTo70Words(): void
    {
        $this->assertSame('<p>Test intro</p>', blog_summary($this->post));

        $note = $this->post('notes', 'note', 'no-intro', 'No intro');
        $note = $this->kirby->impersonate('kirby', fn () => $note->update([
            'intro' => '',
            'text' => str_repeat('one ', 50) . "\n\n" . str_repeat('two ', 30) . "\n\nNot in the summary.",
        ]));

        $summary = blog_summary($note);
        $this->assertSame(80, blog_words($summary));
        $this->assertStringNotContainsString('Not in the summary', $summary);
        $this->assertSame(84, blog_words(blog_content($note)));
    }
}
