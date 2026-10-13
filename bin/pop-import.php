<?php

/**
 * Imports Pop's visits once into "Besucher" (bin/lib/pop.php). The
 * database is a local copy of var/data.db from the server.
 *
 * Usage: php bin/pop-import.php <data.db> [www-root]
 */

$db = $argv[1] ?? '';
$www = rtrim($argv[2] ?? 'www', '/');
if (!is_file($db)) {
    fwrite(STDERR, "Usage: php bin/pop-import.php <data.db> [www-root]\n");
    exit(1);
}
require dirname(__DIR__) . '/vendor/autoload.php';
require $www . '/kirby/bootstrap.php';
require __DIR__ . '/lib/pop.php';
$kirby = new Kirby(['roots' => ['index' => realpath($www)]]);

$result = pop_import($db);
printf("%d visits read, %d days written, %d days kept (Kirby had them)\n", $result['rows'], $result['days'], $result['kept']);
if ($result['unknown'] !== []) {
    printf("%d URLs without a page, the most visited:\n", count($result['unknown']));
    foreach (array_slice($result['unknown'], 0, 20, true) as $url => $n) {
        printf("  %6d  %s\n", $n, $url);
    }
}
