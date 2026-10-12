<?php

/**
 * Creates the local host config (env.php) and, for a fresh site only,
 * the media salt (.salt). Existing files stay untouched.
 *
 * env.php is config: per host, not in git, not in backups.
 * .salt is state: created once per site, then only ever restored from a backup.
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
