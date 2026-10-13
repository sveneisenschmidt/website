<?php

namespace Website\Tests\Integration;

/**
 * A published post gets a new URL from a new slug or date: the old URL
 * leads there, and the feed keeps the post's GUID (plugins/blog/urls.php).
 */
final class MovedPostTest extends IntegrationTestCase
{
    public function testANewSlugLeavesARedirect(): void
    {
        $this->rename('new-name');

        $this->assertSame([['from' => '/2026-10-07-test-photo/', 'to' => '/2026-10-07-new-name/', 'rss' => 'keep']], blog_redirects());
    }

    public function testANewDateLeavesARedirect(): void
    {
        $this->kirby->impersonate('kirby', fn() => $this->post->update(['date' => '2026-10-08 08:00:00']));

        $this->assertSame([['from' => '/2026-10-07-test-photo/', 'to' => '/2026-10-08-test-photo/', 'rss' => 'keep']], blog_redirects());
    }

    public function testOlderRedirectsMoveOnAndKeepTheirGuid(): void
    {
        blog_redirects_write([['from' => '/posts/daily-photo-test/', 'to' => '/2026-10-07-test-photo/', 'pop' => 'keep', 'rss' => 'keep']]);

        $this->rename('new-name');

        $this->assertSame([
            ['from' => '/posts/daily-photo-test/', 'to' => '/2026-10-07-new-name/', 'pop' => 'keep', 'rss' => 'keep'],
            ['from' => '/2026-10-07-test-photo/', 'to' => '/2026-10-07-new-name/'],
        ], blog_redirects());
        $this->assertStringContainsString('<guid isPermaLink="true">http://localhost/posts/daily-photo-test/?source=rss</guid>', blog_rss());
    }

    public function testTheOldSlugAgainLeavesNoLoop(): void
    {
        $this->rename('new-name');
        $this->rename('test-photo');

        $this->assertSame([['from' => '/2026-10-07-new-name/', 'to' => '/2026-10-07-test-photo/']], blog_redirects());
        $this->assertStringContainsString('<guid isPermaLink="true">http://localhost/2026-10-07-test-photo/?source=rss</guid>', blog_rss());
    }

    public function testTheFeedKeepsTheGuid(): void
    {
        $this->rename('new-name');

        $xml = blog_rss();

        $this->assertStringContainsString('<link>http://localhost/2026-10-07-new-name/?source=rss</link>', $xml);
        $this->assertStringContainsString('<guid isPermaLink="true">http://localhost/2026-10-07-test-photo/?source=rss</guid>', $xml);
    }

    public function testDraftsAndOtherChangesLeaveNone(): void
    {
        $draft = $this->post('notes', 'note', 'a-draft', 'A Draft', 'draft');
        $this->kirby->impersonate('kirby', fn() => $draft->changeSlug('still-a-draft'));
        $this->kirby->impersonate('kirby', fn() => $this->post->update(['title' => 'New Title']));

        $this->assertSame([], blog_redirects());
    }

    private function rename(string $slug): void
    {
        $this->post = $this->kirby->impersonate('kirby', fn() => $this->kirby->page($this->post->id())->changeSlug($slug));
    }
}
