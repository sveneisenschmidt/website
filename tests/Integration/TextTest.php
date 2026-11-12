<?php

namespace Website\Tests\Integration;

use Kirby\Filesystem\Dir;
use Website\Blog\Text;

/**
 * Text and the page models: Markdown, summary, plain text and words,
 * and the meta description cut from the summary.
 */
final class TextTest extends IntegrationTestCase
{
    public function testMarkdownKeepsLineBreaksAndSetsTypography(): void
    {
        $html = Text::html("A line\nand the next -- \"quoted\" it's... --- done", $this->post);

        // No <br> for a line break; "--" an en dash, "---" an em dash.
        $this->assertSame("<p>A line\nand the next \u{2013} “quoted” it’s… \u{2014} done</p>", $html);
    }

    public function testHeadingsGetAnIdAndAnAnchor(): void
    {
        $html = Text::html("## Fox & Wagtail\n\n## Fox & Wagtail\n\n### Über_uns 2", $this->post);

        $this->assertStringContainsString("<h2 id=\"fox--wagtail\">Fox &amp; Wagtail<a class=\"heading-anchor\" href=\"#fox--wagtail\">#</a>\n</h2>", $html);
        $this->assertStringContainsString('<h2 id="fox--wagtail-1">', $html);
        $this->assertStringContainsString('<h3 id="über_uns-2">', $html);
    }

    public function testPlainTextLeavesOutExifRowsAndScripts(): void
    {
        $html = "<p>One <em>two</em></p>\n<figure>\n<img src=\"a.webp\" alt=\"\">\n<dl class=\"exif\">\n<div>\n<dt>Camera</dt>\n<dd>Canon EOS R5</dd>\n</div>\n</dl></figure>\n"
            . "<p>three &amp; it&rsquo;s</p>\n<div class=\"map\"><script type=\"application/json\">{\"a\": 1}</script>\n</div>";

        $this->assertSame("One two\n\n\n\nthree & it’s", Text::plain($html));
        $this->assertSame(5, Text::words(Text::plain($html)));
    }

    public function testDescriptionIsTheSummaryCutAfterAWord(): void
    {
        $post = $this->kirby->impersonate('kirby', fn() => $this->post->update(['intro' => str_repeat('word ', 40)]));

        $this->assertSame(str_repeat('word ', 31) . 'word …', $post->metaDescription());
    }

    public function testPlainTextSkipsFiguresAndMapsWithoutRenderingThem(): void
    {
        $note = $this->post('notes', 'note', 'with-figure', 'With figure');
        $this->upload($this->jpeg(8, 8), 'a.jpg', $note);
        $note = $this->kirby->impersonate('kirby', fn() => $note->update([
            'intro' => '',
            'text' => "(image: a.jpg)\n\nOne two.\n\n## A heading\n\n(map:)\n\nThree (image: a.jpg caption: (four)) five.",
        ]));
        // The upload rendered the thumbnails (Images::render()). Without them,
        // each figure that renders writes a job file, and the test counts these.
        Dir::remove($this->kirby->root('media') . '/pages/notes/with-figure');
        $thumbs = fn() => glob($this->kirby->root('media') . '/pages/notes/with-figure/*/.jobs/*.json') ?: [];

        $this->assertSame("One two.\nA heading\nThree  five.", $note->summary());
        $this->assertSame(6, Text::words($note->plainText()));
        $this->assertSame([], $thumbs(), 'no figure was rendered');

        Text::html($note->text()->value(), $note);
        $this->assertNotSame([], $thumbs(), 'the page itself renders the figures');
    }

    public function testSummaryIsTheIntroOrTheText(): void
    {
        $this->assertSame('Test intro', $this->post->summary());

        $note = $this->post('notes', 'note', 'no-intro', 'No intro');
        $note = $this->kirby->impersonate('kirby', fn() => $note->update([
            'intro' => '',
            'text' => "Cliff's **new** blog.\n\nGo check it out!",
        ]));

        $this->assertSame("Cliff’s new blog.\nGo check it out!", $note->summary());
        $this->assertSame('Cliff’s new blog. Go check it out!', $note->metaDescription());
    }

    public function testASpoilerCoversEachWordOfItsSection(): void
    {
        $html = Text::html("Before.\n\n(spoiler)\n\nHe is *his father*, [see](https://example.com).\n\nFox & wagtail.\n\n(/spoiler)\n\nAfter.", $this->post);

        $word = fn(string $text) => "<span class=\"spoiler-word\">$text</span>";
        $this->assertSame(
            "<p>Before.</p>\n<div class=\"spoiler\">\n"
            . "<p>{$word('He')} {$word('is')} <em>{$word('his')} {$word('father')}</em>{$word(',')} <a href=\"https://example.com\">{$word('see')}</a>{$word('.')}</p>\n"
            . "<p>{$word('Fox')} {$word('&amp;')} {$word('wagtail.')}</p>\n"
            . "</div>\n<p>After.</p>",
            $html,
        );
    }

    public function testASpoilerLeavesScriptsAndAttributesAsTheyAre(): void
    {
        $html = Text::spoilers("<p>(spoiler)</p>\n<div class=\"map\"><script type=\"application/json\">{\"a\": \"b c\"}</script>\n</div>\n<p><img alt=\"two words\" src=\"a.webp\"> one</p>\n<p>(/spoiler)</p>");

        $this->assertSame(
            "<div class=\"spoiler\">\n<div class=\"map\"><script type=\"application/json\">{\"a\": \"b c\"}</script>\n</div>\n"
            . "<p><img alt=\"two words\" src=\"a.webp\"> <span class=\"spoiler-word\">one</span></p>\n</div>",
            $html,
        );
    }

    public function testASpoilerMarkerNeedsNoBlankLineAroundIt(): void
    {
        $word = fn(string $text) => "<span class=\"spoiler-word\">$text</span>";
        $section = "<div class=\"spoiler\">\n<p>{$word('Secret.')}</p>\n</div>";

        $this->assertSame($section, Text::html("(spoiler)\nSecret.\n\n(/spoiler)", $this->post), 'no blank line after (spoiler)');
        $this->assertSame($section, Text::html("(spoiler)\n\nSecret.\n(/spoiler)", $this->post), 'no blank line before (/spoiler)');
        $this->assertSame(
            "<p>Before.</p>\n$section\n<p>After.</p>",
            Text::html("Before.\n(spoiler)\nSecret.\n(/spoiler)\nAfter.", $this->post),
            'the markers inside one paragraph',
        );
        $this->assertSame($section, Text::html("(spoiler)  \nSecret.\n(/spoiler)  \n", $this->post), 'two spaces after a marker');
    }

    public function testASpoilerMarkerInACodeBlockStaysCode(): void
    {
        $html = Text::html("```\n(spoiler)\ntext\n(/spoiler)\n```", $this->post);

        $this->assertSame("<pre><code>(spoiler)\ntext\n(/spoiler)</code></pre>", $html);
    }

    public function testASpoilerWithoutItsEndRunsToTheEndOfItsParent(): void
    {
        $word = fn(string $text) => "<span class=\"spoiler-word\">$text</span>";

        $this->assertSame(
            "<p>Before.</p>\n<div class=\"spoiler\">\n<p>{$word('Open')} {$word('end.')}</p></div>",
            Text::html("Before.\n\n(spoiler)\n\nOpen end.", $this->post),
        );
        // The section starts in the quote and its end marker is outside:
        // it ends with the quote, and the HTML stays well-formed.
        $this->assertSame(
            "<blockquote>\n<div class=\"spoiler\">\n<p>{$word('Secret.')}</p>\n</div></blockquote>\n<p>More.</p>\n<p>(/spoiler)</p>",
            Text::html("> (spoiler)\n> Secret.\n\nMore.\n\n(/spoiler)", $this->post),
        );
    }

    public function testASpoilerInAQuoteAndWithOtherLineFormats(): void
    {
        $word = fn(string $text) => "<span class=\"spoiler-word\">$text</span>";
        $section = "<div class=\"spoiler\">\n<p>{$word('Secret.')}</p>\n</div>";

        $this->assertSame("<blockquote>\n$section\n</blockquote>", Text::html("> (spoiler)\n> Secret.\n> (/spoiler)", $this->post));
        $this->assertSame($section, Text::html(" (spoiler)\nSecret.\n (/spoiler)", $this->post), 'a space before the markers');
        $this->assertSame($section, Text::html("(spoiler)\r\n\r\nSecret.\r\n\r\n(/spoiler)", $this->post), 'Windows line ends');
    }

    public function testAMarkerThatIsNoLineOfItsOwnStaysText(): void
    {
        $this->assertSame('<p>A (spoiler) in a sentence, no (/spoiler) line.</p>', Text::html('A (spoiler) in a sentence, no (/spoiler) line.', $this->post));
        $this->assertSame("<p><em>a\n(spoiler)\nb</em></p>", Text::html("*a\n(spoiler)\nb*", $this->post));
        $this->assertSame("<p><em>a</em>(spoiler)</p>", Text::html('*a*(spoiler)', $this->post));
        // A post that explains the markers writes them as code or emphasis.
        $this->assertSame("<p><code>(spoiler)</code></p>\n<p>Shown.</p>\n<p><code>(/spoiler)</code></p>", Text::html("`(spoiler)`\n\nShown.\n\n`(/spoiler)`", $this->post));
        $this->assertSame("<p><em>(spoiler)</em></p>\n<p>Shown.</p>", Text::html("*(spoiler)*\n\nShown.", $this->post));
        $this->assertSame("<p><a href=\"https://example.com\">(spoiler)</a></p>\n<p>Shown.</p>", Text::html("[(spoiler)](https://example.com)\n\nShown.", $this->post));
    }

    public function testASpoilerKeepsLineBreaksAndSpecialSpacesOutOfItsBars(): void
    {
        $word = fn(string $text) => "<span class=\"spoiler-word\">$text</span>";

        // Two spaces at the end of a line are a line break in Markdown.
        $this->assertSame(
            "<p>Before.</p>\n<div class=\"spoiler\">\n<p>{$word('Secret.')}<br>\n{$word('more')}</p>\n</div>",
            Text::html("Before.  \n(spoiler)  \nSecret.  \nmore\n(/spoiler)", $this->post),
        );
        // A no-break space and a thin space are spaces between two bars.
        $this->assertSame(
            "<div class=\"spoiler\">\n<p>{$word('a')}&nbsp;{$word('b')} {$word('c')}\u{2009}{$word('d')}</p>\n</div>",
            Text::html("(spoiler)\n\na\u{00A0}b c\u{2009}d\n\n(/spoiler)", $this->post),
        );
    }

    public function testASpoilerKeepsAttributesAndCommentsAsTheyAre(): void
    {
        $html = Text::spoilers("<p>(spoiler)</p>\n<p><a title=\"x > y\" href=\"/a\">one</a><!-- a > b --></p>\n<p>(/spoiler)</p>");

        $this->assertSame("<div class=\"spoiler\">\n<p><a title=\"x > y\" href=\"/a\"><span class=\"spoiler-word\">one</span></a><!-- a > b --></p>\n</div>", $html);
    }

    public function testTwoSpoilersInOneText(): void
    {
        $html = Text::html("(spoiler)\nOne.\n(/spoiler)\n\nBetween.\n\n(spoiler)\nTwo.\n(/spoiler)", $this->post);

        $this->assertSame(2, substr_count($html, '<div class="spoiler">'));
        $this->assertStringContainsString('<p>Between.</p>', $html);
    }

    public function testTheSummaryLeavesOutSpoilersAndTheWordCountKeepsThem(): void
    {
        $note = $this->post('notes', 'note', 'spoiled', 'Spoiled');
        $note = $this->kirby->impersonate('kirby', fn() => $note->update([
            'intro' => '',
            'text' => "One two.\n\n(spoiler)\n\nThree four.\n\n(/spoiler)\n\nFive.",
        ]));

        $this->assertSame("One two.\nFive.", $note->summary());
        $this->assertSame('One two. Five.', $note->metaDescription());
        $this->assertSame(5, $note->wordCount());
    }

    public function testTheSummaryLeavesOutEverySpoilerThatThePageCovers(): void
    {
        $note = $this->post('notes', 'note', 'spoiled-summary', 'Spoiled summary');
        $summary = function (string $intro, string $text) use (&$note) {
            $note = $this->kirby->impersonate('kirby', fn() => $note->update(['intro' => $intro, 'text' => $text]));
            return $note->summary();
        };

        $this->assertSame('One.', $summary('', "One.\n(spoiler)\nSecret.\n(/spoiler)"), 'no blank lines');
        $this->assertSame('One.', $summary('', "One.\n\n> (spoiler)\n> Secret.\n> (/spoiler)"), 'in a quote');
        $this->assertSame("One.\nTwo.", $summary('', "One.\r\n\r\n(spoiler)\r\n\r\nSecret.\r\n\r\n(/spoiler)\r\n\r\nTwo."), 'Windows line ends');
        // The section starts in the intro and ends in the text.
        $this->assertSame('Teaser.', $summary("Teaser.\n\n(spoiler)\n\nIntro secret.", "Text secret.\n\n(/spoiler)\n\nEnd."));
        $this->assertStringNotContainsString('secret', $note->metaDescription());
        $this->assertStringContainsString('<p>End.</p>', $note->bodyHtml());
        $this->assertStringNotContainsString('(/spoiler)', $note->bodyHtml());
    }
}
