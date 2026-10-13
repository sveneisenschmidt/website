<?php

namespace Website\Tests\Integration;

use Website\Analytics\Campaign;
use Website\Blog\FileCache;
use Website\Tests\Support\TestSite;

/**
 * The page cache holds the HTML of every rendered page (config.php,
 * `Website\Blog\FileCache`). A rendered page goes into it, and flushes from
 * parallel Panel requests do not fail.
 */
final class CacheTest extends IntegrationTestCase
{
    public function testPagesGoIntoTheCache(): void
    {
        $this->assertInstanceOf(FileCache::class, $this->kirby->cache('pages'));

        $this->post->render();

        $this->assertTrue($this->kirby->cache('pages')->exists('photos/test-photo.latest.html'));
    }

    /**
     * A link from the feed or a social network carries ?source=rss, utm_*
     * or fbclid. www/index.php strips them, so the page still comes from
     * the cache. Any other parameter, such as the query of the search, still
     * skips it.
     */
    public function testTrackingParametersKeepThePageCacheable(): void
    {
        $_GET = ['source' => 'rss', 'utm_source' => 'mastodon', 'fbclid' => 'x'];
        Campaign::strip();
        $kirby = TestSite::app($this->root, $this->options, ['server' => ['REQUEST_URI' => '/2026-10-07-test-photo/']]);

        $this->assertSame([], $_GET);
        $this->assertSame('rss', Campaign::source());
        $this->assertTrue($kirby->page('photos/test-photo')->isCacheable());

        $_GET = ['q' => 'bike', 'utm_medium' => 'social'];
        Campaign::strip();
        $kirby = TestSite::app($this->root, $this->options, ['server' => ['REQUEST_URI' => '/2026-10-07-test-photo/']]);

        $this->assertSame(['q' => 'bike'], $_GET);
        $this->assertFalse($kirby->page('photos/test-photo')->isCacheable());
    }

    public function testParallelFlushesDoNotFail(): void
    {
        $procs = [];
        $pipes = [];
        for ($i = 1; $i <= 8; $i++) {
            $procs[$i] = proc_open([PHP_BINARY, __DIR__ . '/../Support/flush-worker.php', $this->root, '100'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
        }
        $results = [];
        foreach ($procs as $i => $proc) {
            $results[$i] = trim(stream_get_contents($pipes[$i][1]) . stream_get_contents($pipes[$i][2]));
            proc_close($proc);
        }

        $this->assertSame(array_fill(1, 8, 'ok'), $results);
    }
}
