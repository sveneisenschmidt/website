<?php

namespace Website\Tests\Integration;

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\Page;
use PHPUnit\Framework\TestCase;
use Website\Tests\Support\TestSite;

abstract class IntegrationTestCase extends TestCase
{
    protected string $root;
    protected App $kirby;
    /** A listed photo under the unlisted section page "photos". */
    protected Page $post;
    /** Options for the test site, on top of config.php. */
    protected array $options = [];
    /** Further App props for the test site, such as roles. */
    protected array $props = [];

    protected function setUp(): void
    {
        $this->root = TestSite::create();
        $this->kirby = TestSite::app($this->root, $this->options, $this->props);
        $this->post = $this->post();
    }

    protected function tearDown(): void
    {
        TestSite::remove($this->root);
    }

    /**
     * A post under its section page, as the Panel creates it. The section
     * page ("photos", "trips", ...) is created once and published unlisted,
     * like the import leaves it.
     */
    protected function post(
        string $section = 'photos',
        string $template = 'photo',
        string $slug = 'test-photo',
        string $title = 'Test Photo',
        string $status = 'listed',
    ): Page {
        return $this->kirby->impersonate('kirby', function () use ($section, $template, $slug, $title, $status) {
            $parent = $this->kirby->page($section) ?? $this->kirby->site()->createChild([
                'slug' => $section,
                'template' => $section,
                'content' => ['title' => ucfirst($section)],
            ])->changeStatus('unlisted');
            $post = $parent->createChild([
                'slug' => $slug,
                'template' => $template,
                'content' => ['title' => $title, 'date' => '2026-10-07 20:00:00', 'intro' => 'Test intro'],
            ]);
            return $status === 'draft' ? $post : $post->changeStatus($status);
        });
    }

    /**
     * Upload to the post (or to $page) as the Panel does: through the image
     * blueprint and our hooks.
     */
    protected function upload(string $source, string $filename, ?Page $page = null): File
    {
        return $this->kirby->impersonate('kirby', fn () => ($page ?? $this->post)->createFile([
            'source' => $source,
            'filename' => $filename,
            'template' => 'image',
        ]));
    }

    /** A plain JPEG of the given size, without EXIF. */
    protected function jpeg(int $width, int $height, string $name = 'plain.jpg'): string
    {
        $path = $this->root . '/' . $name;
        imagejpeg(imagecreatetruecolor($width, $height), $path, 70);
        return $path;
    }
}
