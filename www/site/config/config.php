<?php

/**
 * The Kirby configuration for every host. A value that differs per host,
 * such as debug or url, goes into env.php, and env.php overrides the values
 * here. env.php is in neither git nor the backups; see env.example.php.
 */

// Dates in the Panel (also the default "now" of a post's date) and in the
// feed use Berlin time.
date_default_timezone_set('Europe/Berlin');

// json_encode() writes a float with the digits of serialize_precision. The
// web PHP of the host has a fixed value there and wrote the map point
// 51.1547 as 51.15469999999999828332875040359795093536376953125 (live HTML,
// 2026-10-13). -1 writes the shortest form that reads back as the same float.
ini_set('serialize_precision', '-1');

$env = is_file(__DIR__ . '/env.php') ? require __DIR__ . '/env.php' : [];

// Kirby puts a hash with this salt into every media URL (the thumbnails
// under /media/). The salt is in .salt, which every backup includes and git
// does not. A new salt would change every image URL.
$salt = is_file(__DIR__ . '/.salt') ? trim(file_get_contents(__DIR__ . '/.salt')) : null;

// A host with env.php but without .salt has lost its salt, and it must come
// back from a backup. Without this check, Kirby would derive a salt from the
// content path, and every media URL would change without an error.
if (is_file(__DIR__ . '/env.php') && ($salt === null || $salt === '')) {
    throw new RuntimeException('site/config/.salt is missing: restore it from a backup.');
}

return array_replace_recursive([
    'locale' => 'en_US.utf-8',
    'content' => array_filter(['salt' => $salt]),
    // A single line break in Markdown does not become a <br>. The posts are
    // written for this setting.
    'markdown' => ['breaks' => false],
    // SmartyPants replaces plain punctuation with typographic characters.
    // "qDe" sets curly quotes (q), "--" as en dash and "---" as em dash (D),
    // and ellipses (e). Kirby's default makes "--" an em dash.
    'smartypants' => ['attr' => 'qDe'],
    // GD, plus conversion to sRGB for photos with another color profile
    // (Website\Blog\SrgbGd).
    'thumbs' => ['driver' => 'blog-gd'],
    // The page cache keeps the rendered HTML of each page. Kirby empties it
    // on every change in the Panel. The cache type blog-file
    // (Website\Blog\FileCache) empties it under a lock, so parallel uploads
    // do not fail. About is never cached, because its visitor numbers
    // change all day.
    'cache' => [
        'pages' => ['active' => true, 'type' => 'blog-file', 'ignore' => ['about']],
    ],
    'routes' => require __DIR__ . '/routes.php',
    'panel' => [
        'language' => 'de',
        // Each post type gets its own entry in the Panel menu, which links to
        // its parent page (for example /panel/pages/photos). The entry of the
        // site is marked as current only outside these four pages. Areas and
        // tabs have German ids, because the ids appear in Panel URLs such as
        // /panel/besucher. All other names in the code are English.
        'menu' => function (Kirby\Cms\App $kirby) {
            $types = array_column(Website\Blog\Types::ALL, null, 'section');
            $path = $kirby->path();
            $inType = fn(string $slug) => preg_match('!^panel/pages/' . $slug . '(\+|/|$)!', $path) === 1;
            $menu = ['site' => ['current' => fn(?string $current) => $current === 'site' && !array_filter(array_keys($types), $inType)]];
            foreach ($types as $slug => $type) {
                $menu[$slug] = ['label' => $type['menu'], 'icon' => $type['icon'], 'link' => "pages/$slug", 'current' => fn() => $inType($slug)];
            }
            return [...$menu, '-', 'besucher', 'verlauf', 'assistent', '-', 'users', 'system'];
        },
        // The buttons at the top of a page in the Panel: Kirby's own and the
        // button of the assistant (plugins/assistant), which shows on posts
        // and About.
        'viewButtons' => [
            'page' => ['open', 'preview', '-', 'assistant', 'settings', 'languages', 'status'],
        ],
    ],
], $env);
