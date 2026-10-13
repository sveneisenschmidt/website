<?php

/**
 * Prints the URL path of every page, one per line: first "/", then every
 * listed and unlisted page except home and error, then four routes of
 * config/routes.php. bin/restore-test.sh fetches each path from the original
 * site and from the restored copy.
 *
 * Usage: php bin/page-paths.php [www-root]
 */

$www = rtrim($argv[1] ?? 'www', '/');
require dirname(__DIR__) . '/vendor/autoload.php';
require $www . '/kirby/bootstrap.php';
$kirby = new Kirby(['roots' => ['index' => realpath($www)]]);

echo "/\n";
foreach ($kirby->site()->index() as $page) {
    if ($page->isHomePage() || $page->isErrorPage()) {
        continue;
    }
    echo parse_url($page->url(), PHP_URL_PATH), "\n";
}
foreach (['/index.xml', '/sitemap.xml', '/robots.txt', '/llms.txt'] as $route) {
    echo $route, "\n";
}
