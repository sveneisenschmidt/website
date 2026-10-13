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
        $page = $kirby->impersonate('kirby', fn() => $kirby->site()->createChild([
            'slug' => 'about',
            'template' => 'default',
            'content' => ['title' => 'About'],
        ])->changeStatus('unlisted'));

        $html = $page->render();

        $this->assertStringContainsString('<title>About · Personal Blog</title>', $html);
        $this->assertStringContainsString('<h1>About</h1>', $html);
    }

    public function testTitlesAreEscapedOnce(): void
    {
        $kirby = TestSite::app($this->root);
        $page = $kirby->impersonate('kirby', fn() => $kirby->site()->createChild([
            'slug' => 'fox-and-wagtail',
            'template' => 'default',
            'content' => ['title' => 'Fox & Wagtail'],
        ])->changeStatus('unlisted'));

        $html = $page->render();

        $this->assertStringContainsString('<title>Fox &amp; Wagtail · Personal Blog</title>', $html);
        $this->assertStringContainsString('<h1>Fox &amp; Wagtail</h1>', $html);
    }
}
