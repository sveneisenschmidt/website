<?php

namespace Website\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Website\Tests\Support\TestSite;

final class RenderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TestSite::create();
    }

    protected function tearDown(): void
    {
        TestSite::remove($this->root);
    }

    public function testDefaultTemplateRendersPageAndSiteTitle(): void
    {
        $kirby = TestSite::app($this->root);
        // TestSite loads the shared config from www/site.
        $this->assertSame('de', $kirby->option('panel.language'));
        $page = $kirby->impersonate('kirby', fn () => $kirby->site()->createChild([
            'slug' => 'about',
            'template' => 'default',
            'content' => ['title' => 'About'],
        ])->changeStatus('unlisted'));

        $html = $page->render();

        $this->assertStringContainsString('<title>About · Sven Eisenschmidt - Personal Blog</title>', $html);
        $this->assertStringContainsString('<h1>About</h1>', $html);
    }

    public function testTitlesAreEscapedOnce(): void
    {
        $kirby = TestSite::app($this->root);
        $page = $kirby->impersonate('kirby', fn () => $kirby->site()->createChild([
            'slug' => 'fox-and-wagtail',
            'template' => 'default',
            'content' => ['title' => 'Fox & Wagtail'],
        ])->changeStatus('unlisted'));

        $html = $page->render();

        $this->assertStringContainsString('<title>Fox &amp; Wagtail · Sven Eisenschmidt - Personal Blog</title>', $html);
        $this->assertStringContainsString('<h1>Fox &amp; Wagtail</h1>', $html);
    }

    public function testRendersIntroTextImagesAndTheMapTag(): void
    {
        $kirby = TestSite::app($this->root);
        $page = $kirby->impersonate('kirby', function () use ($kirby) {
            $draft = $kirby->site()->createChild([
                'slug' => 'note-x',
                'template' => 'note',
                'content' => ['title' => 'Note', 'date' => '2026-01-01 10:00:00', 'intro' => 'Hello **world**', 'text' => "(image: a.jpg)\n\n(map:)"],
            ]);
            $draft->createFile(['source' => __DIR__ . '/../fixtures/exif.jpg', 'filename' => 'a.jpg', 'template' => 'image']);
            return $draft->changeStatus('listed');
        });

        $html = $page->render();

        $this->assertStringContainsString('<strong>world</strong>', $html);
        $this->assertStringContainsString('<div class="map"></div>', $html);
        $this->assertMatchesRegularExpression('!<img [^>]*src="http://localhost/media/pages/note-x/[^"]+/a\.jpg"!', $html);
    }
}
