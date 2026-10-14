<?php

namespace Website\Tests\Integration;

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
     * the post add no job file. The job files from before are the previews
     * that the Panel shows.
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
        $jobs = $this->jobs();

        $this->renderThePagesOfThePost();

        $this->assertSame($jobs, $this->jobs());
    }

    /** A replace deletes the thumbnails of the old file. The replace of a cover renders the sizes of a cover again. */
    public function testReplacingTheCoverRendersItsThumbnailsAgain(): void
    {
        $this->createListPages();
        $cover = $this->upload($this->jpeg(40, 30), 'cover.jpg');
        $this->kirby->impersonate('kirby', fn() => $this->post->update(['cover' => [$cover->uuid()->toString()], 'pick' => 'true']));

        $this->kirby->impersonate('kirby', fn() => $this->kirby->page('photos/test-photo')->file('cover.jpg')->replace($this->jpeg(30, 40, 'new.jpg')));
        $jobs = $this->jobs();

        $this->renderThePagesOfThePost();

        $this->assertSame($jobs, $this->jobs());
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

    private function renderThePagesOfThePost(): void
    {
        foreach (['', 'archive', 'archive/photos', '2026-10-07-test-photo', 'index.xml', 'sitemap.xml'] as $path) {
            $this->assertSame(200, $this->kirby->render($path)?->code(), $path);
        }
    }

    /**
     * The job files that wait in the media folder of the post.
     *
     * @return list<string>
     */
    private function jobs(): array
    {
        return array_map('basename', glob($this->kirby->root('media') . '/pages/photos/test-photo/*/.jobs/*.json') ?: []);
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
