<?php

/**
 * Fills and clears the page cache in a loop, as many Panel saves would.
 * CacheTest runs 8 of these processes at the same time.
 * Usage: php flush-worker.php <test-root> <rounds>
 * Prints "ok" or the error. Every PHP warning counts as an error.
 */

use Website\Tests\Support\TestSite;

require __DIR__ . '/../bootstrap.php';

set_error_handler(function (int $severity, string $message, string $file, int $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

[, $root, $rounds] = $argv;

try {
    $cache = TestSite::app($root)->cache('pages');
    for ($i = 0; $i < (int) $rounds; $i++) {
        $cache->set('page-' . getmypid() . '-' . $i, ['html' => 'x']);
        $cache->flush();
    }
    echo 'ok';
} catch (Throwable $e) {
    echo $e->getMessage();
    exit(1);
}
