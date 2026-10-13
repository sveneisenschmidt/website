<?php

/**
 * Clears the page cache of every host name: site/cache/<host>/pages.
 * It takes the lock Website\Blog\FileCache takes for a flush, exclusive on
 * site/cache/<host>/.flush.lock, so a page that writes its cache entry at
 * that moment waits instead of failing. Plain PHP without the site's
 * classes: the deploy runs it from stdin on the server for any release,
 * also one with older code.
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
