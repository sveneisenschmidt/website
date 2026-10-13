<?php

namespace Website\Tests\Support;

/**
 * A small Hugo content folder with one example of everything the import
 * handles: bundle and single-file posts, a draft, intro, img with caption
 * and photographer, a map with route, a ref link, the email shortcode,
 * rawhtml, a Markdown image, archive, search and about.
 */
final class HugoFixture
{
    /** 2026-01-01 00:00:00 UTC */
    public const TIME = 1767225600;

    /** Writes the folder below $dir and returns its path. */
    public static function create(string $dir): string
    {
        $c = $dir . '/hugo';
        foreach (['photos' => 'Photos', 'trips' => 'Trips', 'notes' => 'Notes', 'essays' => 'Essays'] as $section => $title) {
            self::write("$c/$section/_index.md", "+++\ntitle = \"$title\"\n[build]\n  list = 'never'\n  render = 'never'\n+++\n");
        }

        self::write("$c/photos/aika/index.md", <<<'MD'
+++
date = '2026-03-11T20:40:00'
title = "Aika"
type = "photo"
cover = "IMG_2334.JPG"
pick = true
+++

It was dark, all images were blurry.

<!--more-->

{{< img src="IMG_2334.JPG" alt="Aika" caption="Taken on [the roof](https://example.com)" photographer="Nadine" >}}
MD);
        self::jpeg("$c/photos/aika/IMG_2334.JPG");

        self::write("$c/trips/2026-summer-vacation-5/index.md", <<<'MD'
+++
date = '2026-07-15T22:00:00'
title = "Summer Vacation, Day 5: Brighton"
type = "trip"
trip = "summer-2026"
cover = "A6404180.jpeg"
+++

We packed up and met [Aika]({{< ref "aika" >}}) again.

<!--more-->

{{< img src="A6404180.jpeg" alt="California at the pitch">}}

{{< map route="route.json" zoom="true" >}}
50.8297,0.3924,Norman's Bay, East Sussex
50.8224,-0.0970,Brighton
{{< /map >}}
MD);
        self::jpeg("$c/trips/2026-summer-vacation-5/A6404180.jpeg");
        self::write("$c/trips/2026-summer-vacation-5/route.json", '{"type":"LineString","coordinates":[[0.3924,50.8297],[-0.097,50.8224]]}');

        self::write("$c/notes/gemma.md", "+++\ndate = '2026-06-13T09:00:00'\ntitle = \"Gemma\"\ntype = \"note\"\n+++\n\nA note without intro.\n");
        self::write("$c/notes/draft-note.md", "+++\ndate = '2026-09-01T10:00:00'\ntitle = \"Draft\"\ntype = \"note\"\ndraft = true\n+++\n\nNot yet.\n");
        self::write("$c/essays/ambiguity.md", "+++\ndate = '2026-01-22T08:00:00'\ntitle = \"Ambiguity\"\ntype = \"essay\"\n+++\n\nWrite me an {{< email subject=\"Hypo App\" >}}.\n\n<!--more-->\n\nThe rest.\n");

        self::write("$c/about/index.md", <<<'MD'
+++
date = '2026-01-02T12:03:26+01:00'
showDate = false
draft = false
title = 'About'
label = 'Personal blog'
# Von Hand
lastmod = '2026-09-12'
build.list = 'never'
+++

![A picture of Sven](sven.jpg)
{class="inline"}

{{< rawhtml >}}
<ul class="built">
<li>Reader</li>

<li>Hypo</li>
</ul>
{{< /rawhtml >}}

{{< rawhtml >}}
<div id="site-stats"></div>
<script defer>
fetch("https://pop.eisenschmidt.website/api/stats");
</script>
{{< /rawhtml >}}
MD);
        self::jpeg("$c/about/sven.jpg");

        self::write("$c/archive/_index.md", "+++\ntitle = \"Archive\"\nlayout = \"archive\"\n[params]\n  stream = \"all\"\n[build]\n  list = 'never'\n+++\n");
        self::write("$c/archive/essays.md", "+++\ntitle = \"Archive · Essays\"\nlayout = \"archive\"\n[params]\n  stream = \"essays\"\n[build]\n  list = 'never'\n+++\n");
        self::write("$c/search/_index.md", "+++\ntitle = \"Search\"\nlayout = \"search\"\n[build]\n  list = 'never'\n+++\n");
        return $c;
    }

    private static function write(string $path, string $text): void
    {
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $text);
        touch($path, self::TIME);
    }

    private static function jpeg(string $path): void
    {
        @mkdir(dirname($path), 0755, true);
        imagejpeg(imagecreatetruecolor(8, 8), $path, 70);
        touch($path, self::TIME);
    }
}
