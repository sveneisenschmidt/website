<?php

namespace Website\Tests\Integration;

use Website\Tests\Support\TestSite;

/**
 * Search on the server: /search/?q= renders the results, search.js asks
 * /search.json while typing (plugins/blog/posts.php, templates/search*).
 */
final class SearchTest extends IntegrationTestCase
{
    private array $get;

    protected function setUp(): void
    {
        parent::setUp();
        $this->get = $_GET;
        $this->kirby->impersonate('kirby', function () {
            $this->kirby->site()->createChild(['slug' => 'search', 'template' => 'search', 'draft' => false, 'content' => ['title' => 'Search']]);
            $this->post = $this->post->update(['text' => "A grey heron by the pond.\n\n(image: heron.jpg)\n\n[Link](https://example.com/kingfisher)"]);
        });
        $heron = $this->upload($this->jpeg(8, 8), 'heron.jpg');
        $this->kirby->impersonate('kirby', fn () => $heron->update(['alt' => 'Bird in the reeds']));
        $this->post('notes', 'note', 'pond-note', 'Pond Note');
    }

    protected function tearDown(): void
    {
        $_GET = $this->get;
        parent::tearDown();
    }

    public function testFindsEveryWordInTitleTextAndAltText(): void
    {
        $this->assertSame(['photos/test-photo'], blog_search('Heron REEDS')->keys());
        $this->assertSame(['notes/pond-note', 'photos/test-photo'], blog_search('pond')->keys());
        $this->assertSame([], blog_search('heron kingfisher')->keys(), 'link targets do not count');
        $this->assertSame([], blog_search('jpg')->keys(), 'file names do not count');
        $this->assertSame([], blog_search('  ')->keys());
    }

    public function testResultsComeFromTheServer(): void
    {
        $html = $this->search('heron');

        $this->assertStringContainsString('value="heron"', $html);
        $this->assertStringContainsString('<a class="archive-title" href="' . $this->post->url() . '">Test Photo</a>', $html);
        $this->assertStringContainsString('data-search-input', $html, 'search.js is inline');
    }

    public function testNothingFound(): void
    {
        $this->assertStringContainsString('<span>Nothing found</span><span>0</span>', $this->search('zebra'));
    }

    public function testJsonForSearchJs(): void
    {
        $json = json_decode($this->search('pond', 'json'), true);

        $this->assertSame(['url', 'title', 'date', 'thumb', 'thumb2x'], array_keys($json[0]));
        $this->assertSame(['Pond Note', 'Test Photo'], array_column($json, 'title'));
        $this->assertSame('2026-10-07', $json[1]['date']);
    }

    /** The search page for ?q=$query: Kirby reads the query when it starts. */
    private function search(string $query, string $type = 'html'): string
    {
        $_GET = ['q' => $query];
        return TestSite::app($this->root, $this->options)->page('search')->render([], $type);
    }
}
