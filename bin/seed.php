<?php

/**
 * Example content for local development until the Hugo import exists
 * (phase 2): site, home, about and error page, plus a local Panel login.
 * Output is deterministic: same files and file times on every run.
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

/** Kirby content file: "Key: value" blocks separated by "----". */
function write_txt(string $path, array $fields): void
{
    $blocks = [];
    foreach ($fields as $k => $v) {
        $v = (string)$v;
        $blocks[] = $k . ':' . (str_contains($v, "\n") ? "\n\n" . $v : ' ' . $v);
    }
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, implode("\n\n----\n\n", $blocks) . "\n");
}

/** Stable 16-char id from a string, used as Kirby UUID. */
function uid(string $s): string
{
    return substr(hash('sha256', 'website:' . $s), 0, 16);
}

rrmdir($content);
mkdir($content, 0755, true);

write_txt("$content/site.txt", ['Title' => 'Sven Eisenschmidt', 'Uuid' => uid('site')]);
write_txt("$content/home/home.txt", ['Title' => 'Home', 'Uuid' => uid('home')]);
write_txt("$content/about/default.txt", ['Title' => 'About', 'Uuid' => uid('about')]);
write_txt("$content/error/error.txt", ['Title' => 'Page not found', 'Uuid' => uid('error')]);

// Fixed file times for files and folders: Kirby's modified() reads both.
$mtime = strtotime('2026-10-12 12:00:00 UTC');
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($content, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $f) {
    touch($f->getPathname(), $mtime);
}
touch($content, $mtime);

// Dev account, only if no account exists yet (keeps a real local account untouched).
$accounts = $root . '/www/site/accounts';
// Only account folders count: Kirby also writes a .logins file there.
$hasAccounts = count(glob($accounts . '/*', GLOB_ONLYDIR) ?: []) > 0;
if (!$hasAccounts) {
    require $root . '/vendor/autoload.php';
    require $root . '/www/kirby/bootstrap.php';
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

echo "Seeded 4 pages into www/content.\n";
