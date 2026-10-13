<?php

namespace Website\Tests\Integration;

use Website\Blog\FileCache;

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
