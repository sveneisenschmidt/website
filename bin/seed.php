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

rrmdir($content);
$counts = hugo_import($root . '/content', $content, 'blog_read_exif');

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
