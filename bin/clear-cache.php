<?php

/**
 * Clears the page cache of every host name, under the same lock as the
 * site itself (Website\Blog\FileCache). rm -rf of site/cache would race
 * with a page that writes its cache entry at that moment.
 * Kirby keeps one page cache per host name: site/cache/<host>/pages.
 * From concertshotsbyluisa, with the cache type of this site from src/ and
 * Kirby's dependencies in vendor/, both next to www/. The deploy runs it
 * on the server from stdin.
 *
 * Usage: php bin/clear-cache.php [www-root]
 */

$www = rtrim($argv[1] ?? 'www', '/');
if (!is_file($www . '/kirby/bootstrap.php')) {
    fwrite(STDERR, "Not a site root: $www\n");
    exit(1);
}
require dirname(realpath($www)) . '/vendor/autoload.php';
require $www . '/kirby/bootstrap.php';

$cache = $www . '/site/cache';
$count = 0;
foreach (glob($cache . '/*/pages', GLOB_ONLYDIR) ?: [] as $dir) {
    $prefix = basename(dirname($dir)) . '/pages';
    (new Website\Blog\FileCache(['root' => $cache, 'prefix' => $prefix]))->flush();
    $count++;
}
echo "Page cache cleared ($count host" . ($count === 1 ? '' : 's') . ")\n";
