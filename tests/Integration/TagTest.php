<?php

namespace Website\Tests\Integration;

use Website\Blog\Exif;
use Website\Blog\Images;
use Website\Blog\Text;

/**
 * The Kirby tags of the site. A Kirby tag is a command in the text, such as
 * (image: a.jpg). (image:) renders a figure with its EXIF rows, and (map:)
 * renders the map of a trip.
 */
final class TagTest extends IntegrationTestCase
{
    public function testImageIsAFigureWithExifRows(): void
    {
        $file = $this->upload(__DIR__ . '/../fixtures/exif.jpg', 'a.jpg');
        $file = $this->kirby->impersonate('kirby', fn() => $file->update([
            'alt' => 'Fox & wagtail',
            'caption' => 'Taken on [the roof](https://example.com) of the old mill',
            'photographer' => 'Nadine',
        ]));

        $html = Text::html('(image: a.jpg)', $this->post);

        $this->assertStringStartsWith("<figure>\n<a class=\"frame\" href=\"{$file->url()}\">", $html);
        $this->assertStringContainsString('class="frame-picture"', $html);
        $this->assertStringContainsString('src="' . Images::wide($file, 1600)->url() . '"', $html);
        $this->assertStringContainsString('srcset="' . Images::wide($file, 600)->url() . ' 600w, ' . Images::wide($file, 1000)->url() . ' 1000w, ' . Images::wide($file, 1600)->url() . ' 1600w"', $html);
        $this->assertStringContainsString('sizes="(max-width: 900px) calc(100vw - 2rem), (max-width: 90rem) 63vw, 907px"', $html);
        // Kirby never enlarges an image, so the 60 px wide fixture keeps its
        // width.
        $this->assertStringContainsString("width=\"60\"\nheight=\"40\"\nalt=\"Fox &amp; wagtail\"\nloading=\"lazy\"\ndecoding=\"async\"", $html);
        $this->assertStringContainsString("<dt>Camera</dt>\n<dd>TEST-CAM</dd>", $html);
        $this->assertStringContainsString("<dt>Exposure</dt>\n<dd>1/250s · ISO 3200</dd>", $html);
        $this->assertStringContainsString("<div class=\"caption caption-long\">\n<dt>Caption</dt>\n<dd>Taken on <a href=\"https://example.com\">the roof</a> of the old mill</dd>", $html);
        $this->assertStringContainsString("<div class=\"photographer\">\n<dt>Photographer</dt>\n<dd>Nadine</dd>", $html);
        $this->assertStringNotContainsString("\n\n", $html, 'no blank line: Markdown would end the HTML block');
    }

    public function testExifRowsAreFormatted(): void
    {
        $file = $this->upload($this->jpeg(8, 8), 'b.jpg');
        $exif = function (array $values) use (&$file) {
            $file = $this->kirby->impersonate('kirby', fn() => $file->update($values));
            return Exif::rows($file);
        };

        $this->assertSame(
            ['Camera' => 'Canon EOS R5', 'Lens' => 'RF100-500mm', 'Focal length' => '35mm', 'Aperture' => 'ƒ/8', 'Exposure' => '1/1000s · ISO 800'],
            $exif(['camera' => 'Canon EOS R5', 'lens' => 'RF100-500mm', 'focal' => '35.4', 'aperture' => '8', 'shutter' => '1/1000', 'iso' => '800']),
        );
        $this->assertSame(['Aperture' => 'ƒ/7.1', 'Exposure' => '0.8s'], $exif(['camera' => '', 'lens' => '', 'focal' => '', 'aperture' => '7.1', 'shutter' => '0.8', 'iso' => '']));
        $this->assertSame(['Exposure' => '30.0s'], $exif(['aperture' => '', 'shutter' => '30']));
        $this->assertSame(['Exposure' => 'ISO 100'], $exif(['shutter' => '', 'iso' => '100']));
    }

    public function testTheCoverMovesBetweenPages(): void
    {
        $file = $this->upload($this->jpeg(8, 8), 'c.jpg');
        $this->kirby->impersonate('kirby', fn() => $this->post->update(['cover' => [$file->uuid()->toString()]]));

        $html = Text::html('(image: c.jpg)', $this->kirby->page('photos/test-photo'));

        $this->assertStringContainsString('style="view-transition-name: ' . $this->post->transitionName() . '"', $html);
    }

    /**
     * Only the first image of the post loads eagerly with a high fetch
     * priority, also when the cover comes later in the text.
     */
    public function testOnlyTheFirstImageLoadsAtOnce(): void
    {
        $cover = $this->upload($this->jpeg(8, 8), 'c.jpg');
        $this->upload($this->jpeg(8, 8), 'e.jpg');
        $this->kirby->impersonate('kirby', fn() => $this->post->update(['cover' => [$cover->uuid()->toString()], 'text' => "(image: e.jpg)\n\n(image: c.jpg)"]));
        $html = $this->kirby->page('photos/test-photo')->bodyHtml();

        $this->assertSame(1, substr_count($html, 'loading="eager"'));
        $this->assertMatchesRegularExpression('!e-1600x-q80\\.webp"[^>]*loading="eager"\nfetchpriority="high"!s', $html);
        $this->assertMatchesRegularExpression('!c-1600x-q80\\.webp"[^>]*loading="lazy"!s', $html);
    }

    public function testImgclassIsAPlainMarkdownImage(): void
    {
        $file = $this->upload($this->jpeg(8, 8), 'd.jpg');
        $this->kirby->impersonate('kirby', fn() => $file->update(['alt' => 'A portrait']));

        $html = Text::html("(image: d.jpg imgclass: inline)\n\nHello", $this->post);

        $this->assertSame("<img src=\"{$file->url()}\" alt=\"A portrait\" class=\"inline\">\n<p>Hello</p>", $html);
    }

    public function testMapWithPointsAndRoute(): void
    {
        $trip = $this->post('trips', 'trip', 'a-trip', 'A Trip');
        $route = $this->kirby->impersonate('kirby', fn() => $trip->createFile([
            'source' => $this->write('route.json', '{"type":"LineString","coordinates":[[12.24,51.12],[12.25,51.13]]}'),
            'filename' => 'route.json',
            'template' => 'route',
        ]));
        $trip = $this->kirby->impersonate('kirby', fn() => $trip->update([
            'route' => [$route->uuid()->toString()],
            'mappoints' => [['label' => 'Pegau', 'lat' => '51.1660', 'lon' => '12.2480'], ['label' => "St Michael's <b>Mount</b>", 'lat' => '50.1', 'lon' => '-5.47']],
            'mapzoom' => 'true',
        ]));

        $html = Text::html("Before\n\n(map:)", $trip);

        // Points as numbers, the JSON escaped for the attribute.
        $this->assertStringContainsString('<div class="map" data-zoomable="true" data-points="[{&quot;label&quot;:&quot;Pegau&quot;,&quot;lat&quot;:51.166,&quot;lon&quot;:12.248},{&quot;label&quot;:&quot;St Michael&#039;s ', $html);
        $this->assertStringContainsString('&quot;lat&quot;:50.1,&quot;lon&quot;:-5.47}]"><script type="application/json">{"type":"LineString","coordinates":[[12.24,51.12],[12.25,51.13]]}</script>' . "\n</div>", $html);
        $this->assertStringNotContainsString('<b>', $html, 'a label is text');
    }

    private function write(string $name, string $content): string
    {
        file_put_contents($this->root . '/' . $name, $content);
        return $this->root . '/' . $name;
    }
}
