<?php

/**
 * Router script for PHP's built-in web server (make dev, bin/restore-test.sh).
 * PHP's server ignores www/.htaccess, so this script applies its blocks.
 * A request for a dot file or for content/, site/ or kirby/ goes to
 * index.php, and Kirby answers 404, as on Apache. Every other request goes
 * to Kirby's own router.php.
 */
$uri = urldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (preg_match('#(^|/)\.(?!well-known/)|^/(content|site|kirby)/#', $uri)) {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
    return true;
}
return require $_SERVER['DOCUMENT_ROOT'] . '/kirby/router.php';
