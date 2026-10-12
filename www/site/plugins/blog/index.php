<?php

use Kirby\Cms\App as Kirby;
use Kirby\Cms\File;

/**
 * The blog: posts in the sections photos, trips, notes and essays.
 */

require_once __DIR__ . '/exif.php';

Kirby::plugin('sveneisenschmidt/blog', [
    'hooks' => [
        // EXIF goes into the file's content once, so it is in every backup
        // and can be corrected in the Panel. A replaced photo brings its own.
        'file.create:after' => fn (File $file) => blog_store_exif($file),
        'file.replace:after' => fn (File $newFile) => blog_store_exif($newFile, true),
    ],
    'tags' => [
        // Where the map of a post sits in its text. Route and points are
        // page fields; phase 3 draws the map.
        'map' => [
            'html' => fn () => '<div class="map"></div>',
        ],
    ],
]);
