<?php

/**
 * Writes or checks the manifest of a release: manifest.json in the release
 * folder. It lists every file of the release with its sha256 hash and every
 * symlink with its target. bin/deploy.sh writes it into the build and checks
 * the uploaded release against it. The workflow Integrity checks the live
 * release once a day, so a change on the server outside CI fails that run.
 *
 * The manifest is on the same server as the release. Someone who changes the
 * release on purpose can change the manifest too. The check finds edits by
 * hand and changes from tools that do not know the manifest.
 *
 * The script uses no class of the site: it runs over stdin on the server.
 *
 * Usage: php bin/manifest.php write|check <release-folder>
 */

const MANIFEST = 'manifest.json';

/**
 * Returns path => "sha256:<hash>" for each file and path => "link:<target>"
 * for each symlink below $dir, sorted by path. The manifest is not in it.
 * The iterator does not follow a symlink into shared/.
 *
 * @return array<string, string>
 */
function manifest_entries(string $dir): array
{
    $entries = [];
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($items as $item) {
        $path = substr($item->getPathname(), strlen($dir) + 1);
        if ($item->isLink()) {
            $entries[$path] = 'link:' . readlink($item->getPathname());
        } elseif ($item->isFile() && $path !== MANIFEST) {
            $entries[$path] = 'sha256:' . hash_file('sha256', $item->getPathname());
        }
    }
    ksort($entries, SORT_STRING);
    return $entries;
}

$mode = $argv[1] ?? '';
$dir = rtrim($argv[2] ?? '', '/');
if (!in_array($mode, ['write', 'check'], true) || $dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "Usage: php bin/manifest.php write|check <release-folder>\n");
    exit(1);
}
$file = $dir . '/' . MANIFEST;
$id = basename((string) realpath($dir));

if ($mode === 'write') {
    file_put_contents($file, json_encode(manifest_entries($dir), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    exit(0);
}

$expected = json_decode((string) @file_get_contents($file), true);
if (!is_array($expected)) {
    fwrite(STDERR, "$id has no readable " . MANIFEST . ".\n");
    exit(1);
}
$actual = manifest_entries($dir);
$problems = [];
foreach ($expected as $path => $entry) {
    if (!isset($actual[$path])) {
        $problems[] = "missing  $path";
    } elseif ($actual[$path] !== $entry) {
        $problems[] = "changed  $path";
    }
}
foreach (array_keys(array_diff_key($actual, $expected)) as $path) {
    $problems[] = "added    $path";
}
if ($problems !== []) {
    fwrite(STDERR, "$id differs from its manifest:\n" . implode("\n", $problems) . "\n");
    exit(1);
}
echo "$id matches its manifest: " . count($actual) . " files and symlinks\n";
