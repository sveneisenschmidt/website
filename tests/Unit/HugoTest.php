<?php

namespace Website\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HugoTest extends TestCase
{
    public function testReadsFrontMatterWithTablesCommentsAndDottedKeys(): void
    {
        $md = "+++\ntitle = \"Archive · Essays\"\n# a comment\ndraft = false\nbuild.list = 'never'\n[params]\n  stream = \"essays\"\n+++\n\nBody\n";
        $this->assertSame([
            ['title' => 'Archive · Essays', 'draft' => false, 'build.list' => 'never', 'params.stream' => 'essays'],
            "\nBody\n",
        ], hugo_front_matter($md));
    }

    public function testRejectsUnknownFrontMatter(): void
    {
        $this->expectException(RuntimeException::class);
        hugo_front_matter("+++\ntags = ['a']\n+++\n");
    }

    public function testReadsUnquotedDates(): void
    {
        [$fm] = hugo_front_matter("+++\ndate = 2026-06-13T09:00:00\n+++\n");
        $this->assertSame(['date' => '2026-06-13T09:00:00'], $fm);
    }

    public function testDatesAreBerlinLocalTime(): void
    {
        $this->assertSame('2026-03-11 20:40:00', hugo_date('2026-03-11T20:40:00'));
        $this->assertSame('2026-01-02 12:03:26', hugo_date('2026-01-02T12:03:26+01:00'));
        $this->assertSame('2026-07-15 23:00:00', hugo_date('2026-07-15T21:00:00Z'));
    }

    public function testSplitsIntroAtMore(): void
    {
        $this->assertSame(['Intro.', 'Rest.'], hugo_split_intro("\nIntro.\n\n<!--more-->\n\nRest.\n"));
        $this->assertSame(['', 'Only text.'], hugo_split_intro("\nOnly text.\n"));
    }

    public function testConvertsImagesAndKeepsTheirTexts(): void
    {
        [$text, $images, $map] = hugo_convert('A {{< img src="a.jpg" alt="An \"A\"" caption="See [x](https://x.test/?tab=1)" photographer="Nadine" >}} B {{< img src="b.png" alt="B">}}', fn () => '');
        $this->assertSame('A (image: a.jpg) B (image: b.png)', $text);
        $this->assertSame([
            'a.jpg' => ['alt' => 'An "A"', 'caption' => 'See [x](https://x.test/?tab=1)', 'photographer' => 'Nadine'],
            'b.png' => ['alt' => 'B', 'caption' => '', 'photographer' => ''],
        ], $images);
        $this->assertNull($map);
    }

    public function testConvertsMarkdownImagesOfTheBundle(): void
    {
        [$text, $images] = hugo_convert('![A picture](sven.jpg) and ![x](https://x.test/y.jpg)', fn () => '');
        $this->assertSame('(image: sven.jpg) and ![x](https://x.test/y.jpg)', $text);
        $this->assertSame(['sven.jpg' => ['alt' => 'A picture', 'caption' => '', 'photographer' => '']], $images);
    }

    public function testConvertsTheMapIntoPageFields(): void
    {
        [$text, , $map] = hugo_convert("Before\n\n{{< map route=\"route.json\" zoom=\"true\" >}}\n50.8297,0.3924,Norman's Bay, East Sussex\n50.8224,-0.0970,Brighton\n{{< /map >}}\n", fn () => '');
        $this->assertSame("Before\n\n(map:)\n", $text);
        $this->assertSame([
            'route' => 'route.json',
            'zoom' => true,
            'points' => [
                ['lat' => '50.8297', 'lon' => '0.3924', 'label' => "Norman's Bay, East Sussex"],
                ['lat' => '50.8224', 'lon' => '-0.0970', 'label' => 'Brighton'],
            ],
        ], $map);
    }

    public function testRejectsASecondMap(): void
    {
        $this->expectException(RuntimeException::class);
        hugo_convert('{{< map route="a.json" >}}{{< /map >}}{{< map route="b.json" >}}{{< /map >}}', fn () => '');
    }

    public function testConvertsRefLinksToUuidLinks(): void
    {
        [$text] = hugo_convert('Back to [Tournai]({{< ref "2026-summer-vacation-2" >}}).', fn (string $slug) => "uuid-of-$slug");
        $this->assertSame('Back to (link: page://uuid-of-2026-summer-vacation-2 text: Tournai).', $text);
    }

    public function testConvertsTheEmailShortcode(): void
    {
        [$text] = hugo_convert('Send me an {{< email subject="Hypo App" >}}.', fn () => '');
        $this->assertSame('Send me an [Email](mailto:sven.eisenschmidt@gmail.com?subject=Hypo%20App).', $text);
    }

    public function testUnwrapsRawHtmlWithoutBlankLines(): void
    {
        // A blank line would end the HTML block in Markdown; the rest would show as code.
        [$text] = hugo_convert("A\n\n{{< rawhtml >}}\n<script>\nlet a = 1;\n\nlet b = 2;\n</script>\n{{< /rawhtml >}}\n", fn () => '');
        $this->assertSame("A\n\n<script>\nlet a = 1;\nlet b = 2;\n</script>\n", $text);
    }

    public function testFoldsAnImageClassIntoTheTag(): void
    {
        [$text] = hugo_convert("![Sven](sven.jpg)\n{class=\"inline\"}\n\nText", fn () => '');
        $this->assertSame("(image: sven.jpg imgclass: inline)\n\nText", $text);
    }

    public function testStopsOnOtherMarkdownAttributes(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('{#anchor}');
        hugo_convert("## Title\n{#anchor}\n", fn () => '');
    }

    public function testUnderscoreEmphasisAroundALinkBecomesStars(): void
    {
        [$text] = hugo_convert('Read _[The Day](https://x.test/The_Day)_ now.', fn () => '');
        $this->assertSame('Read *[The Day](https://x.test/The_Day)* now.', $text);
    }

    public function testStopsOnUnknownShortcodesAndArguments(): void
    {
        foreach (['{{< youtube abc >}}', '{{< img src="a.jpg" width="3" >}}', '{{% note %}}x{{% /note %}}'] as $body) {
            try {
                hugo_convert($body, fn () => '');
                $this->fail("No exception for $body");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
