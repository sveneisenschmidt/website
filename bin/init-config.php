<?php

/**
 * Creates the local configuration when it does not exist: .env from
 * .env.example, and www/site/config/.salt. make setup and make config run
 * it. An existing file stays as it is.
 *
 * .env holds the local values and keys (see .env.example). .salt is state:
 * Kirby builds the media URLs with it. A new site gets it once; a site with
 * content restores its .salt from a backup (make pull does that).
 *
 * Usage: php bin/init-config.php [www-root]
 */

$www = $argv[1] ?? 'www';
$dir = "$www/site/config";

if (!is_dir($dir) || !is_file('.env.example')) {
    fwrite(STDERR, "Run from the repository root: php bin/init-config.php [www-root]\n");
    exit(1);
}

if (is_file('.env')) {
    echo ".env exists, left unchanged\n";
} else {
    copy('.env.example', '.env');
    echo "Created .env from .env.example: fill in the values\n";
}

$salt = "$dir/.salt";
if (is_file($salt)) {
    echo ".salt exists, left unchanged\n";
} else {
    file_put_contents($salt, bin2hex(random_bytes(24)) . "\n");
    echo "Created $salt\n";
}
