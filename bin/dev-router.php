<?php

/**
 * Router for PHP's built-in server (make dev, bin/restore-test.sh)
 * with the blocks of www/.htaccess: dot files and the folders content,
 * site and kirby go to Kirby, which answers 404, as on Apache. Everything
 * else goes through Kirby's router.php.
 */
$uri = urldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (preg_match('#(^|/)\.(?!well-known/)|^/(content|site|kirby)/#', $uri)) {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
    return true;
}
return require $_SERVER['DOCUMENT_ROOT'] . '/kirby/router.php';
