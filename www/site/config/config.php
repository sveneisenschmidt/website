<?php

/**
 * Shared configuration. Lives in git.
 * Host-specific values (debug and the like) go into env.php: not in git,
 * not in backups, imported per host. See env.example.php.
 */

// Dates in the Panel (default: now) and in feeds are Berlin time.
date_default_timezone_set('Europe/Berlin');

$env = is_file(__DIR__ . '/env.php') ? require __DIR__ . '/env.php' : [];

// The media salt is state, not host config: it lives in .salt, which is part
// of every backup. If it changed, every image URL would change.
$salt = is_file(__DIR__ . '/.salt') ? trim(file_get_contents(__DIR__ . '/.salt')) : null;

// A host config without .salt means the salt got lost: restore it from a
// backup. Kirby would otherwise derive a salt from the content path, and
// every media URL would change without an error.
if (is_file(__DIR__ . '/env.php') && ($salt === null || $salt === '')) {
    throw new RuntimeException('site/config/.salt is missing: restore it from a backup.');
}

return array_replace_recursive([
    'locale' => 'en_US.utf-8',
    'content' => array_filter(['salt' => $salt]),
    // A single line break in a text is no <br>: the posts are written that way.
    'markdown' => ['breaks' => false],
    // Curly quotes and ellipses; "--" is an en dash and "---" an em dash
    // (Kirby's default makes "--" an em dash).
    'smartypants' => ['attr' => 'qDe'],
    // GD, plus conversion to sRGB for photos with another color profile
    // (plugins/blog/srgb.php).
    'thumbs' => ['driver' => 'blog-gd'],
    // HTML cache. Kirby clears it on every change in the Panel. The type
    // clears it under a lock, for parallel uploads (plugins/blog/cache.php).
    'cache' => [
        'pages' => ['active' => true, 'type' => 'blog-file'],
    ],
    'routes' => require __DIR__ . '/routes.php',
    'panel' => [
        'language' => 'de',
        // Each post type gets its own entry in the menu, linked to its page in
        // the tree under Site. Site is current only outside these four pages.
        'menu' => function (Kirby\Cms\App $kirby) {
            $types = ['photos' => ['Fotos', 'image'], 'trips' => ['Reisen', 'map'], 'notes' => ['Notizen', 'text'], 'essays' => ['Essays', 'book']];
            $path = $kirby->path();
            $inType = fn (string $slug) => preg_match('!^panel/pages/' . $slug . '(\+|/|$)!', $path) === 1;
            $menu = ['site' => ['current' => fn (?string $current) => $current === 'site' && !array_filter(array_keys($types), $inType)]];
            foreach ($types as $slug => [$label, $icon]) {
                $menu[$slug] = ['label' => $label, 'icon' => $icon, 'link' => "pages/$slug", 'current' => fn () => $inType($slug)];
            }
            return [...$menu, '-', 'besucher', 'verlauf', 'assistent', '-', 'users', 'system'];
        },
        // Kirby's page buttons plus "Assistent" (plugins/assistant). It shows
        // on posts and About.
        'viewButtons' => [
            'page' => ['open', 'preview', '-', 'assistant', 'settings', 'languages', 'status'],
        ],
    ],
], $env);
