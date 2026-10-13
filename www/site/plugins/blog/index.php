<?php

use Kirby\Cms\App as Kirby;
use Kirby\Cms\File;
use Kirby\Cms\Page;
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
require_once __DIR__ . '/feeds.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/urls.php';

Kirby::plugin('sveneisenschmidt/blog', [
    'hooks' => [
        // EXIF goes into the file's content once, so it is in every backup
        // and can be corrected in the Panel. A replaced photo brings its own.
        'file.create:after' => fn (File $file) => blog_store_exif($file),
        'file.replace:after' => fn (File $newFile) => blog_store_exif($newFile, true),
        // A new slug or date gives a published post a new URL: the old one leads there.
        // The old page of changeSlug has no content any more: its date comes from the new one.
        'page.changeSlug:after' => fn (Page $newPage, Page $oldPage) => blog_redirect_moved($newPage, blog_post_path($newPage, $oldPage->slug())),
        'page.update:after' => fn (Page $newPage, Page $oldPage) => blog_redirect_moved($newPage, blog_post_path($oldPage)),
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
    // One template for the four post types.
    'templates' => array_fill_keys(['photo', 'trip', 'note', 'essay'], __DIR__ . '/../../templates/post.php'),
    // All published posts, newest first, posts of the same time by title.
    'collections' => [
        'posts' => fn () => pages(BLOG_SECTIONS)->children()->listed()->sortBy('date', 'desc', 'title', 'asc'),
    ],
    'cacheTypes' => ['blog-file' => BlogFileCache::class],
    // Every page: its URL ends with a slash, a post's is /YYYY-MM-DD-slug/ (urls.php).
    'pageModels' => ['default' => BlogPage::class],
]);
