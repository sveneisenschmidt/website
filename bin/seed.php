<?php

/**
 * Builds www/content from the Hugo site in content/ (the import of phase 2)
 * and creates a local Panel login. Same input, same files and file times.
 *
 * Usage: php bin/seed.php [--force]
 */

$root = dirname(__DIR__);
$content = $root . '/www/content';
$force = in_array('--force', $argv, true);

if (is_dir($content) && !$force) {
    fwrite(STDERR, "www/content exists. Use --force (or make kirby-seed FORCE=1) to replace it.\n");
    exit(1);
}

// rrmdir would empty the target of a symlink.
if (is_link($content)) {
    fwrite(STDERR, "www/content is a symlink. Refusing to replace it.\n");
    exit(1);
}

require $root . '/vendor/autoload.php';
require $root . '/www/kirby/bootstrap.php';
require_once $root . '/www/site/plugins/blog/exif.php';
require_once $root . '/bin/lib/hugo.php';

if (!function_exists('exif_read_data')) {
    fwrite(STDERR, "PHP has no exif extension: the import would lose all camera data.\n");
    exit(1);
}

// Local development login. Only ever created locally by this script;
// accounts are state and never go into git.
const DEV_EMAIL = 'dev@sven.eisenschmidt.test';
const DEV_PASSWORD = 'dev-panel-login';

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        ($f->isDir() && !$f->isLink()) ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

// Import next to www/content and swap only on success: a failed import
// (an unknown field in a new post) leaves the current content as it is.
$staging = $content . '.new';
rrmdir($staging);
try {
    $counts = hugo_import($root . '/content', $staging, 'blog_read_exif');
} catch (Throwable $e) {
    rrmdir($staging);
    fwrite(STDERR, "Import failed, www/content is unchanged.\n" . $e->getMessage() . "\n");
    exit(1);
}
rrmdir($content);
if (!rename($staging, $content)) {
    fwrite(STDERR, "Cannot move $staging to $content.\n");
    exit(1);
}

// Dev account, only if no account exists yet (keeps a real local account untouched).
$accounts = $root . '/www/site/accounts';
// Only account folders count: Kirby also writes a .logins file there.
$hasAccounts = count(glob($accounts . '/*', GLOB_ONLYDIR) ?: []) > 0;
if (!$hasAccounts) {
    $kirby = new Kirby(['roots' => ['index' => $root . '/www']]);
    $kirby->impersonate('kirby');
    $kirby->users()->create([
        'email' => DEV_EMAIL,
        'password' => DEV_PASSWORD,
        'role' => 'admin',
        'language' => 'de',
        'name' => 'Dev',
    ]);
    echo "Dev login for the Panel (make kirby-dev, then /panel): see DEV_EMAIL and DEV_PASSWORD in bin/seed.php\n";
}

$posts = $counts['photo'] + $counts['trip'] + $counts['note'] + $counts['essay'];
printf(
    "Imported %d posts (%d photo, %d trip, %d note, %d essay), %d drafts, %d files.\n",
    $posts, $counts['photo'], $counts['trip'], $counts['note'], $counts['essay'], $counts['drafts'], $counts['files']
);
