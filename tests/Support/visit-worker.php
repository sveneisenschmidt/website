<?php

/**
 * Page views of one browser in its own process, as parallel requests.
 * Usage: php visit-worker.php <test-root> <ip> <views>
 * Prints "ok" or the error. Every PHP warning counts as an error.
 */

use Website\Tests\Support\TestSite;

require __DIR__ . '/../bootstrap.php';

set_error_handler(function (int $severity, string $message, string $file, int $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

[, $root, $ip, $views] = $argv;

try {
    $_SERVER['REMOTE_ADDR'] = $ip;
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/131.0';
    // The options of AnalyticsTest: the page cache is on, as it will be live.
    $kirby = TestSite::app($root, ['cache' => ['pages' => ['active' => true]]]);
    for ($i = 0; $i < (int)$views; $i++) {
        $kirby->render('photos');
    }
    echo 'ok';
} catch (Throwable $e) {
    echo $e->getMessage();
    exit(1);
}
