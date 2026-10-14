<?php

namespace Website\Blog;

use Closure;
use Kirby\Filesystem\Dir;

/**
 * A lock on a file, for work that parallel requests must not do at the
 * same time. FileCache locks the page cache with it, Images the rendering
 * of thumbnails. The system releases a lock when the request ends, also
 * after a fatal error.
 */
final class Lock
{
    /**
     * Runs $action under the lock $mode (LOCK_SH or LOCK_EX) on $file and
     * returns its result. With $wait, it waits at most $wait seconds, and
     * when the lock stays taken it returns null without running $action.
     * When the lock file cannot be opened, $action runs without the lock.
     *
     * @template T
     * @param Closure(): T $action
     * @return ($wait is null ? T : T|null)
     */
    public static function run(string $file, int $mode, Closure $action, ?float $wait = null): mixed
    {
        Dir::make(dirname($file));
        $handle = fopen($file, 'c');
        if ($handle === false) {
            return $action();
        }
        try {
            if ($wait === null) {
                flock($handle, $mode);
            } else {
                $until = microtime(true) + $wait;
                while (!flock($handle, $mode | LOCK_NB)) {
                    if (microtime(true) >= $until) {
                        return null;
                    }
                    usleep(100_000);
                }
            }
            return $action();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
