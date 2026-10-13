<?php

/**
 * Sets keys in a host config (env.php). Other keys stay as they are.
 * The deploy runs it on the server for:
 *   url     the canonical site URL, so every host name and scheme links to one address
 *
 * Usage: php bin/set-env.php <env.php> key=value ...   (value "true"/"false" is a boolean)
 */

$file = $argv[1] ?? null;
$pairs = array_slice($argv, 2);
if ($file === null || $pairs === [] || !is_file($file)) {
    fwrite(STDERR, "Usage: php bin/set-env.php <env.php> key=value ...\n");
    exit(1);
}

$env = require $file;
if (!is_array($env)) {
    fwrite(STDERR, "$file does not return an array\n");
    exit(1);
}
// var_export cannot write closures or objects back.
$plain = true;
array_walk_recursive($env, function ($v) use (&$plain) {
    $plain = $plain && (is_scalar($v) || $v === null);
});
if (!$plain) {
    fwrite(STDERR, "$file holds values other than plain data; set the keys by hand\n");
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
if ($new === $env) {
    exit(0);
}

// Write a temporary file and rename it, so no request reads half a file.
$tmp = $file . '.tmp';
file_put_contents($tmp, "<?php\n\nreturn " . var_export($new, true) . ";\n");
rename($tmp, $file);
