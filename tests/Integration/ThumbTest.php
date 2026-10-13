<?php

namespace Website\Tests\Integration;

/**
 * Assets under www/assets and thumbnails through the sRGB driver
 * (Website\Blog\SrgbGd), as on concertshotsbyluisa.de.
 */
final class ThumbTest extends IntegrationTestCase
{
    public function testTheAssetsAreThere(): void
    {
        foreach (['css/base.css', 'js/map.js', 'js/search.js', 'leaflet/leaflet.js'] as $file) {
            $this->assertFileExists($this->kirby->root('assets') . '/' . $file);
        }
    }

    public function testThumbnailsGoThroughTheSrgbDriver(): void
    {
        $this->assertSame('blog-gd', $this->kirby->option('thumbs.driver'));

        $thumb = $this->upload(__DIR__ . '/../fixtures/exif.jpg', 'a.jpg')->thumb(['width' => 30, 'format' => 'webp', 'quality' => 80])->save();

        $this->assertSame('image/webp', mime_content_type($thumb->root()));
        $this->assertSame([30, 20], [$thumb->width(), $thumb->height()]);
    }
}
