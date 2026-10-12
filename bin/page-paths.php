<?php

/**
 * Prints the URL path of every page, one per line: first "/", then every
 * listed and unlisted page except home and error. bin/restore-test.sh
 * fetches each of them from both sites.
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
