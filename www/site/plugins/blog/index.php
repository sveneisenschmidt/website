<?php

use Kirby\Cms\App as Kirby;
use Kirby\Cms\File;
use Kirby\Text\KirbyTag;

/**
 * The blog: posts in the sections photos, trips, notes and essays.
 */

require_once __DIR__ . '/exif.php';
require_once __DIR__ . '/srgb.php';
require_once __DIR__ . '/text.php';
require_once __DIR__ . '/posts.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/tags.php';

Kirby::plugin('sveneisenschmidt/blog', [
    'hooks' => [
        // EXIF goes into the file's content once, so it is in every backup
        // and can be corrected in the Panel. A replaced photo brings its own.
        'file.create:after' => fn (File $file) => blog_store_exif($file),
        'file.replace:after' => fn (File $newFile) => blog_store_exif($newFile, true),
    ],
    'tags' => [
        'image' => [
            'attr' => ['imgclass'],
            'html' => fn (KirbyTag $tag) => blog_image_tag($tag),
        ],
        'map' => [
            'html' => fn (KirbyTag $tag) => blog_map_tag($tag),
        ],
    ],
    // One template for the four post types, as Hugo's page.html.
    'templates' => array_fill_keys(['photo', 'trip', 'note', 'essay'], __DIR__ . '/../../templates/post.php'),
]);
