<?php

use Website\Analytics\Country;

/**
 * Downloads the database "IP to Country Lite" of DB-IP and writes it as
 * <folder>/ranges.bin (src/Analytics/Country.php). DB-IP publishes a new
 * file on the first of each month; until it is out, the file of the month
 * before is used. The deploy runs the script for every release,
 * `make countries` runs it locally.
 *
 * Usage: php bin/countries.php <folder>
 */

$folder = rtrim($argv[1] ?? '', '/');
if ($folder === '') {
    fwrite(STDERR, "Usage: php bin/countries.php <folder>\n");
    exit(1);
}
// Composer's autoloader sits next to the folder: in the repository and in
// the build of a release.
require dirname($folder) . '/vendor/autoload.php';
$base = (string) tempnam(sys_get_temp_dir(), 'website-countries-');
$csv = "$base.csv.gz";
$source = null;
foreach (['now', 'first day of last month'] as $when) {
    $url = 'https://download.db-ip.com/free/dbip-country-lite-' . date('Y-m', strtotime($when)) . '.csv.gz';
    if (@copy($url, $csv)) {
        Country::build($csv, "$folder/ranges.bin");
        $source = $url;
        break;
    }
}
@unlink($csv);
@unlink($base);
if ($source === null) {
    fwrite(STDERR, "Could not download the country database of DB-IP.\n");
    exit(1);
}
echo "Countries from $source\n";
