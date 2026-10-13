<?php

namespace Website\Tests\Integration;

/**
 * plugins/blog/text.php: Markdown, summary, plain text and words, and
 * the meta description cut from the summary.
 */
final class TextTest extends IntegrationTestCase
{
    public function testMarkdownKeepsLineBreaksAndSetsTypography(): void
    {
        $html = blog_html("A line\nand the next -- \"quoted\" it's... --- done", $this->post);

        // No <br> for a line break; "--" an en dash, "---" an em dash.
        $this->assertSame("<p>A line\nand the next \u{2013} “quoted” it’s… \u{2014} done</p>", $html);
    }

    public function testHeadingsGetAnIdAndAnAnchor(): void
    {
        $html = blog_html("## Fox & Wagtail\n\n## Fox & Wagtail\n\n### Über_uns 2", $this->post);

        $this->assertStringContainsString("<h2 id=\"fox--wagtail\">Fox &amp; Wagtail<a class=\"heading-anchor\" href=\"#fox--wagtail\">#</a>\n</h2>", $html);
        $this->assertStringContainsString('<h2 id="fox--wagtail-1">', $html);
        $this->assertStringContainsString('<h3 id="über_uns-2">', $html);
    }

    public function testPlainTextLeavesOutExifRowsAndScripts(): void
    {
        $html = "<p>One <em>two</em></p>\n<figure>\n<img src=\"a.webp\" alt=\"\">\n<dl class=\"exif\">\n<div>\n<dt>Camera</dt>\n<dd>Canon EOS R5</dd>\n</div>\n</dl></figure>\n"
            . "<p>three &amp; it&rsquo;s</p>\n<div class=\"map\"><script type=\"application/json\">{\"a\": 1}</script>\n</div>";

        $this->assertSame("One two\n\n\n\nthree & it’s", blog_plain($html));
        $this->assertSame(5, blog_words(blog_plain($html)));
    }

    public function testDescriptionIsTheSummaryCutAfterAWord(): void
    {
        $post = $this->kirby->impersonate('kirby', fn () => $this->post->update(['intro' => str_repeat('word ', 40)]));

        $this->assertSame(str_repeat('word ', 31) . 'word …', blog_description($post));
    }

    public function testPlainTextSkipsFiguresAndMapsWithoutRenderingThem(): void
    {
        $note = $this->post('notes', 'note', 'with-figure', 'With figure');
        $this->upload($this->jpeg(8, 8), 'a.jpg', $note);
        $note = $this->kirby->impersonate('kirby', fn () => $note->update([
            'intro' => '',
            'text' => "(image: a.jpg)\n\nOne two.\n\n## A heading\n\n(map:)\n\nThree (image: a.jpg caption: (four)) five.",
        ]));
        $thumbs = fn () => glob($this->kirby->root('media') . '/pages/notes/with-figure/*/.jobs/*.json') ?: [];

        $this->assertSame("One two.\nA heading\nThree  five.", blog_summary($note));
        $this->assertSame(6, blog_words(blog_plain_content($note)));
        $this->assertSame([], $thumbs(), 'no figure was rendered');

        blog_html($note->text()->value(), $note);
        $this->assertNotSame([], $thumbs(), 'the page itself renders the figures');
    }

    public function testSummaryIsTheIntroOrTheText(): void
    {
        $this->assertSame('Test intro', blog_summary($this->post));

        $note = $this->post('notes', 'note', 'no-intro', 'No intro');
        $note = $this->kirby->impersonate('kirby', fn () => $note->update([
            'intro' => '',
            'text' => "Cliff's **new** blog.\n\nGo check it out!",
        ]));

        $this->assertSame("Cliff’s new blog.\nGo check it out!", blog_summary($note));
        $this->assertSame('Cliff’s new blog. Go check it out!', blog_description($note));
    }
}
