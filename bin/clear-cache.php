<?php

/**
 * Deletes the page cache, the HTML of every page that Kirby rendered. Kirby
 * keeps one page cache per host name, in site/cache/<host>/pages.
 *
 * The script takes the exclusive lock on site/cache/<host>/.flush.lock, as
 * `Website\Blog\FileCache::flush()` does. A request that writes a cache entry
 * at that moment waits and does not fail. The script uses no class of the
 * site: bin/deploy.sh sends it over stdin to the server, and it must work
 * with the code of every release, an older one too.
 *
 * Usage: php bin/clear-cache.php <www-root>
 */

$www = rtrim($argv[1] ?? 'www', '/');
if (!is_dir($www . '/site')) {
    fwrite(STDERR, "Not a site root: $www\n");
    exit(1);
}

function clear_cache_remove(string $dir): void
{
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

$count = 0;
foreach (glob($www . '/site/cache/*/pages', GLOB_ONLYDIR) ?: [] as $dir) {
    $lock = fopen(dirname($dir) . '/.flush.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        fwrite(STDERR, "No lock for $dir\n");
        exit(1);
    }
    clear_cache_remove($dir);
    flock($lock, LOCK_UN);
    fclose($lock);
    $count++;
}
echo "Page cache cleared ($count host" . ($count === 1 ? '' : 's') . ")\n";
