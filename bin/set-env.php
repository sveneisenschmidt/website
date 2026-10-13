<?php

/**
 * Sets keys in an env.php, the host config, and keeps all other keys.
 * bin/deploy.sh runs it on the server for two keys:
 *   url           the canonical URL of the site. Kirby builds every link
 *                 with it, whatever host name or scheme a request uses.
 *   assistantKey  the API key of the Panel assistant. It comes from stdin,
 *                 never from the command line. An empty stdin keeps the
 *                 current key.
 * The values "true" and "false" become booleans.
 *
 * Usage: php bin/set-env.php <env.php> key=value ... [< assistant key]
 */

$file = $argv[1] ?? null;
$pairs = array_slice($argv, 2);
if ($file === null || $pairs === [] || !is_file($file)) {
    fwrite(STDERR, "Usage: php bin/set-env.php <env.php> key=value ... [< assistant key]\n");
    exit(1);
}

$env = require $file;
if (!is_array($env)) {
    fwrite(STDERR, "$file does not return an array\n");
    exit(1);
}

$new = $env;
foreach ($pairs as $pair) {
    [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
    if ($key === '' || $value === null) {
        fwrite(STDERR, "Not key=value: $pair\n");
        exit(1);
    }
    $new[$key] = match ($value) {
        'true' => true,
        'false' => false,
        default => $value,
    };
    echo "$key: $value\n";
}
// The deploy log is public in GitHub Actions, so the script prints "set" in
// place of the key. A terminal on stdin means a call by hand without a key:
// reading it would wait for input.
$assistantKey = stream_isatty(STDIN) ? '' : trim((string) stream_get_contents(STDIN));
if ($assistantKey !== '') {
    $new['assistantKey'] = $assistantKey;
    echo "assistantKey: set\n";
}
if ($new === $env) {
    exit(0);
}

// Write a temporary file and rename it, so no request reads half a file.
$tmp = $file . '.tmp';
file_put_contents($tmp, "<?php\n\nreturn " . var_export($new, true) . ";\n");
// The file holds the assistant's key. Only the user of the hosting account,
// which PHP runs as, may read it.
chmod($tmp, 0600);
rename($tmp, $file);
