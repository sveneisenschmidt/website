<?php

use Website\Analytics\AccessLog;

/**
 * Fills the country of the stored requests that have none from the access
 * log files of the host (src/Analytics/AccessLog.php). Running it again
 * changes only rows that are still without a country.
 *
 * Usage: php bin/countries-from-log.php <www-root> <log file>...
 *        php -- <www-root> <log file>... < bin/countries-from-log.php   (on the server)
 */

$www = rtrim($argv[1] ?? '', '/');
$files = array_slice($argv, 2);
if (!is_dir("$www/site") || $files === []) {
    fwrite(STDERR, "Usage: php bin/countries-from-log.php <www-root> <log file>...\n");
    exit(1);
}
// Composer's autoloader sits next to www/, in the repository and in each
// release on the server.
$root = dirname((string) realpath($www));
require "$root/vendor/autoload.php";
require "$www/kirby/bootstrap.php";
new Kirby(['roots' => ['index' => realpath($www), 'storage' => "$root/storage", 'countries' => "$root/countries"]]);

$result = AccessLog::fill($files);
echo "Rows without a country: {$result['missing']}, filled from the log: {$result['filled']}\n";
