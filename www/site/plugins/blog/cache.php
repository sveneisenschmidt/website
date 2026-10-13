<?php

use Kirby\Cache\FileCache;
use Kirby\Filesystem\Dir;

/**
 * File cache that does not fail under parallel requests. Kirby flushes the
 * page cache after every content change, and the Panel uploads up to 20
 * files at once. Without a lock, Dir::remove() fails when two flushes run
 * at the same time (scandir() on a folder the other one removed), and a
 * page that writes its cache entry during a flush fails too (the folder
 * vanishes between mkdir and the write). A flush takes the lock alone,
 * writes share it.
 */
class BlogFileCache extends FileCache
{
    public function set(string $key, $value, int $minutes = 0): bool
    {
        return $this->locked(LOCK_SH, fn () => parent::set($key, $value, $minutes));
    }

    public function flush(): bool
    {
        return $this->locked(LOCK_EX, fn () => parent::flush());
    }

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
