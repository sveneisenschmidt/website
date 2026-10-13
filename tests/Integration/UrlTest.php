<?php

namespace Website\Tests\Integration;

/**
 * The URL scheme of the site, from the page models (SitePage, PostPage),
 * so links in the text and the Panel's preview use it too.
 */
final class UrlTest extends IntegrationTestCase
{
    public function testEveryUrlFollowsTheScheme(): void
    {
        $pages = $this->kirby->impersonate('kirby', function () {
            $site = $this->kirby->site();
            $home = $site->createChild(['slug' => 'home', 'template' => 'home', 'draft' => false, 'content' => ['title' => 'Home']]);
            $archive = $site->createChild(['slug' => 'archive', 'template' => 'archive', 'draft' => false, 'content' => ['title' => 'Archive']]);
            return [$home, $archive->createChild(['slug' => 'photos', 'template' => 'archive', 'draft' => false, 'content' => ['title' => 'Archive · Photos']])];
        });

        $this->assertSame('http://localhost/2026-10-07-test-photo/', $this->post->url());
        $this->assertSame('http://localhost/', $pages[0]->url());
        $this->assertSame('http://localhost/archive/photos/', $pages[1]->url());
        $this->assertSame('http://localhost/photos/', $this->kirby->page('photos')->url());
    }

    public function testANewDateIsANewUrl(): void
    {
        $post = $this->kirby->impersonate('kirby', fn() => $this->post->update(['date' => '2026-10-08 06:00:00']));

        $this->assertSame('http://localhost/2026-10-08-test-photo/', $post->url());
    }

    public function testLinksAndThePanelPreviewUseIt(): void
    {
        $uuid = $this->post->uuid()->toString();
        $draft = $this->post('notes', 'note', 'a-draft', 'A Draft', 'draft');

        $this->assertSame('<p><a href="http://localhost/2026-10-07-test-photo/">this photo</a></p>', $this->kirby->kirbytext("(link: $uuid text: this photo)"));
        $this->assertStringStartsWith('http://localhost/2026-10-07-a-draft/?', $this->kirby->impersonate('kirby', fn() => $draft->previewUrl()));
    }
}
