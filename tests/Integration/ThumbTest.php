<?php

namespace Website\Tests\Integration;

use Kirby\Image\Darkroom;
use Kirby\Image\Darkroom\GdLib;
use RuntimeException;
use Website\Blog\Images;
use Website\Tests\Support\TestSite;

/**
 * The assets under www/assets, and thumbnails through the thumbnail driver
 * `Website\Blog\SrgbGd`, which converts photos with a color profile to sRGB.
 * Upload and save render the thumbnails before the first visitor asks for
 * them (`Website\Blog\Images::render()`).
 */
final class ThumbTest extends IntegrationTestCase
{
    /**
     * Kirby renders a thumbnail on the first request of its URL. A page that
     * shows a thumbnail before that writes a job file for it. After the
     * upload of the images and the save of the cover, the pages that show
     * the post leave no job file.
     */
    public function testUploadAndSaveRenderEveryThumbnailThatThePagesShow(): void
    {
        $this->createListPages();
        $cover = $this->upload(__DIR__ . '/../fixtures/exif.jpg', 'cover.jpg');
        $this->upload($this->jpeg(40, 30), 'second.jpg');
        $this->kirby->impersonate('kirby', fn() => $this->post->update([
            'cover' => [$cover->uuid()->toString()],
            'pick' => 'true',
            'text' => "(image: cover.jpg)\n\n(image: second.jpg)",
        ]));

        $this->renderThePagesOfThePost();

        $this->assertSame([], $this->jobs());
    }

    /** A replace deletes the thumbnails of the old file. The replace of a cover renders the sizes of a cover again. */
    public function testReplacingTheCoverRendersItsThumbnailsAgain(): void
    {
        $this->createListPages();
        $cover = $this->upload($this->jpeg(40, 30), 'cover.jpg');
        $this->kirby->impersonate('kirby', fn() => $this->post->update(['cover' => [$cover->uuid()->toString()], 'pick' => 'true']));

        $this->kirby->impersonate('kirby', fn() => $this->kirby->page('photos/test-photo')->file('cover.jpg')->replace($this->jpeg(30, 40, 'new.jpg')));

        $this->renderThePagesOfThePost();

        $this->assertSame([], $this->jobs());
    }

    /** A new focus point gives the crops of the cover new file names. */
    public function testANewFocusPointRendersTheCropsOfTheCover(): void
    {
        $this->createListPages();
        $cover = $this->upload($this->jpeg(40, 30), 'cover.jpg');
        $this->kirby->impersonate('kirby', fn() => $this->post->update(['cover' => [$cover->uuid()->toString()], 'pick' => 'true']));

        $this->kirby->impersonate('kirby', fn() => $this->kirby->page('photos/test-photo')->file('cover.jpg')->update(['focus' => '20.0%, 70.0%']));

        $this->renderThePagesOfThePost();
        $this->assertSame([], $this->jobs());
    }

    /** The media folder of a page has the slug in its path, and Kirby deletes the old folder. */
    public function testANewSlugRendersTheThumbnailsAgain(): void
    {
        $this->createListPages();
        $cover = $this->upload($this->jpeg(40, 30), 'cover.jpg');
        $this->upload($this->jpeg(30, 40), 'second.jpg');
        $this->kirby->impersonate('kirby', fn() => $this->post->update([
            'cover' => [$cover->uuid()->toString()],
            'pick' => 'true',
            'text' => "(image: cover.jpg)\n\n(image: second.jpg)",
        ]));

        $this->kirby->impersonate('kirby', fn() => $this->kirby->page('photos/test-photo')->changeSlug('new-photo'));

        $this->renderThePagesOfThePost('new-photo');
        $this->assertSame([], $this->jobs('new-photo'));
    }

    /** The file name is part of every thumbnail name. */
    public function testANewFileNameRendersTheThumbnailsAgain(): void
    {
        $this->createListPages();
        $cover = $this->upload($this->jpeg(40, 30), 'cover.jpg');
        $this->kirby->impersonate('kirby', fn() => $this->post->update(['cover' => [$cover->uuid()->toString()], 'pick' => 'true']));

        $this->kirby->impersonate('kirby', fn() => $this->kirby->page('photos/test-photo')->file('cover.jpg')->changeName('renamed'));

        $this->renderThePagesOfThePost();
        $this->assertSame([], $this->jobs());
    }

    public function testAFileOfTheSiteGetsNoThumbnails(): void
    {
        $this->kirby->impersonate('kirby', fn() => $this->kirby->site()->createFile(['source' => $this->jpeg(40, 30), 'filename' => 'logo.jpg']));

        $this->assertSame([], glob($this->kirby->root('media') . '/site/*/*.webp') ?: []);
    }

    /**
     * render() makes the thumbnails from a copy with 1600 px on the short
     * side. Each thumbnail gets the size that it would get from the
     * original, and the copy is gone afterwards.
     */
    public function testThumbnailsFromTheCopyHaveTheSizesOfTheOriginal(): void
    {
        $wide = $this->upload($this->jpeg(4000, 2000, 'wide.jpg'), 'wide.jpg');
        $tall = $this->upload($this->jpeg(2000, 4000, 'tall.jpg'), 'tall.jpg');

        Images::render($wide, true);
        Images::render($tall, true);

        $sizes = [];
        foreach (glob($this->kirby->root('media') . '/pages/photos/test-photo/*/*.webp') ?: [] as $path) {
            $sizes[basename($path)] = array_slice(getimagesize($path) ?: [], 0, 2);
        }
        ksort($sizes);
        $this->assertSame([
            'tall-1000x-q80.webp' => [1000, 2000],
            'tall-1200x630-crop-q80.webp' => [1200, 630],
            'tall-1600x-q80.webp' => [1600, 3200],
            'tall-200x200-crop-q85.webp' => [200, 200],
            'tall-240x240-crop-q80.webp' => [240, 240],
            'tall-400x400-crop-q85.webp' => [400, 400],
            'tall-480x480-crop-q80.webp' => [480, 480],
            'tall-48x48-crop-q80.webp' => [48, 48],
            'tall-600x-q80.webp' => [600, 1200],
            'tall-768x768-q80.webp' => [384, 768],
            'tall-96x96-crop-q80.webp' => [96, 96],
            'wide-1000x-q80.webp' => [1000, 500],
            'wide-1200x630-crop-q80.webp' => [1200, 630],
            'wide-1600x-q80.webp' => [1600, 800],
            'wide-200x200-crop-q85.webp' => [200, 200],
            'wide-240x240-crop-q80.webp' => [240, 240],
            'wide-400x400-crop-q85.webp' => [400, 400],
            'wide-480x480-crop-q80.webp' => [480, 480],
            'wide-48x48-crop-q80.webp' => [48, 48],
            'wide-600x-q80.webp' => [600, 300],
            'wide-768x768-q80.webp' => [768, 384],
            'wide-96x96-crop-q80.webp' => [96, 96],
        ], $sizes);
        $this->assertSame([], glob($this->kirby->root('cache') . '/thumbs/*') ?: []);
    }

    /** A render that fails keeps the upload. The job files stay, so Kirby renders on the first request. */
    public function testAFailedRenderKeepsTheUploadAndTheJobFiles(): void
    {
        $failing = new class ([]) extends GdLib {
            /**
             * @param array<string, mixed> $options
             * @return array<string, mixed>
             */
            public function process(string $file, array $options = []): array
            {
                throw new RuntimeException('test driver fails');
            }
        };
        Darkroom::$types['test-fails'] = $failing::class;
        $kirby = TestSite::app($this->root, ['thumbs' => ['driver' => 'test-fails']]);
        $log = $this->root . '/error.log';
        $previous = ini_set('error_log', $log);
        try {
            $file = $kirby->impersonate('kirby', fn() => $kirby->page('photos/test-photo')->createFile(['source' => $this->jpeg(40, 30), 'filename' => 'a.jpg', 'template' => 'image']));
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $this->assertFileExists($file->root());
        $this->assertSame(['a-1000x-q80.webp.json', 'a-1600x-q80.webp.json', 'a-600x-q80.webp.json'], $this->jobs());
        $this->assertStringContainsString('blog thumbnail: test driver fails', (string) file_get_contents($log));
    }

    /** The front page and the two archive pages that list the post. */
    private function createListPages(): void
    {
        $this->kirby->impersonate('kirby', function () {
            $site = $this->kirby->site();
            $site->createChild(['slug' => 'home', 'template' => 'home', 'draft' => false, 'content' => ['title' => 'Home']]);
            $archive = $site->createChild(['slug' => 'archive', 'template' => 'archive', 'draft' => false, 'content' => ['title' => 'Archive', 'stream' => 'all']]);
            $archive->createChild(['slug' => 'photos', 'template' => 'archive', 'draft' => false, 'content' => ['title' => 'Archive · Photos', 'stream' => 'photos']]);
        });
    }

    private function renderThePagesOfThePost(string $slug = 'test-photo'): void
    {
        foreach (['', 'archive', 'archive/photos', '2026-10-07-' . $slug, 'index.xml', 'sitemap.xml'] as $path) {
            $this->assertSame(200, $this->kirby->render($path)?->code(), $path);
        }
    }

    /**
     * The job files of WebP thumbnails that wait in the media folder of the
     * post. Every thumbnail that a page shows is WebP (Images). The Panel
     * writes job files for its own JPEG previews, and these stay out.
     *
     * @return list<string>
     */
    private function jobs(string $slug = 'test-photo'): array
    {
        return array_map('basename', glob($this->kirby->root('media') . '/pages/photos/' . $slug . '/*/.jobs/*.webp.json') ?: []);
    }

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
