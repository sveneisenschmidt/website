<?php

/**
 * Creates www/site/config/env.php and www/site/config/.salt when they do not
 * exist. make setup and make config run it. An existing file stays as it is.
 *
 * env.php is the host config: each host has its own, and it is neither in
 * git nor in a backup. The local env.php turns on Kirby's debug mode.
 * .salt is state: Kirby builds the media URLs with it. A new site gets it
 * once. A site with content must restore its .salt from a backup.
 *
 * Usage: php bin/init-config.php [www-root]
 */

$www = $argv[1] ?? 'www';
$dir = "$www/site/config";

if (!is_dir($dir)) {
    fwrite(STDERR, "Not a site root: $www\n");
    exit(1);
}

$env = "$dir/env.php";
if (is_file($env)) {
    echo "env.php exists, left unchanged\n";
} else {
    file_put_contents($env, "<?php\n\nreturn [\n    'debug' => true,\n];\n");
    echo "Created $env\n";
}

$salt = "$dir/.salt";
if (is_file($salt)) {
    echo ".salt exists, left unchanged\n";
} else {
    file_put_contents($salt, bin2hex(random_bytes(24)) . "\n");
    echo "Created $salt\n";
}
