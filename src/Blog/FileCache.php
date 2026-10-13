<?php

namespace Website\Blog;

use Closure;
use Kirby\Cache\FileCache as KirbyFileCache;
use Kirby\Filesystem\Dir;

/**
 * Kirby's file cache with a lock, so that parallel requests do not fail.
 * Kirby empties the page cache (a flush) after every content change, and
 * the Panel uploads up to 20 files at once. Without the lock, Dir::remove()
 * fails when two flushes run at the same time: one calls scandir() on a
 * folder that the other has removed. A page that writes its cache entry
 * during a flush fails too, because the folder disappears between mkdir and
 * the write. A flush takes the lock exclusively, a write takes it shared.
 * plugins/blog registers the class as the cache type `blog-file`, and
 * bin/clear-cache.php takes the same lock on the server.
 */
class FileCache extends KirbyFileCache
{
    public function set(string $key, mixed $value, int $minutes = 0): bool
    {
        return $this->locked(LOCK_SH, fn() => parent::set($key, $value, $minutes));
    }

    public function flush(): bool
    {
        return $this->locked(LOCK_EX, fn() => parent::flush());
    }

    /** @param Closure(): bool $action */
    private function locked(int $mode, Closure $action): bool
    {
        Dir::make(dirname($this->root));
        $lock = fopen(dirname($this->root) . '/.flush.lock', 'c');
        if ($lock === false) {
            return $action();
        }
        try {
            flock($lock, $mode);
            return $action();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
