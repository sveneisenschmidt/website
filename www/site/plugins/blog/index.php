<?php

use Kirby\Cms\App as Kirby;
use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Image\Darkroom;
use Website\Blog\Exif;
use Website\Blog\FileCache;
use Website\Blog\Posts;
use Website\Blog\Redirects;
use Website\Blog\SrgbGd;
use Website\Blog\Tags;
use Website\Blog\Types;
use Website\Models\EssayPage;
use Website\Models\PostPage;
use Website\Models\SitePage;
use Website\Models\TripPage;

/**
 * The blog: posts in the sections photos, trips, notes and essays. The
 * code lives in src/ (Website\Blog, Website\Models), loaded by Composer;
 * this file registers it with Kirby.
 */

// The thumbs driver of config.php: GD, plus conversion to sRGB.
Darkroom::$types['blog-gd'] = SrgbGd::class;

Kirby::plugin('sveneisenschmidt/blog', [
    'hooks' => [
        // EXIF goes into the file's content once, so it is in every backup
        // and can be corrected in the Panel. A replaced photo brings its own.
        'file.create:after' => fn(File $file) => Exif::store($file),
        'file.replace:after' => fn(File $newFile) => Exif::store($newFile, true),
        // A new slug or date gives a published post a new URL: the old one leads there.
        // The old page of changeSlug has no content any more: its date comes from the new one.
        'page.changeSlug:after' => function (Page $newPage, Page $oldPage) {
            if ($newPage instanceof PostPage) {
                Redirects::moved($newPage, $newPage->postPath($oldPage->slug()));
            }
        },
        'page.update:after' => function (Page $newPage, Page $oldPage) {
            if ($newPage instanceof PostPage && $oldPage instanceof PostPage) {
                Redirects::moved($newPage, $oldPage->postPath());
            }
        },
    ],
    'tags' => [
        'image' => [
            'attr' => ['imgclass'],
            'html' => Tags::image(...),
        ],
        'map' => [
            'html' => Tags::map(...),
        ],
    ],
    // One template and one controller for the four post types.
    'templates' => array_fill_keys(Types::templates(), __DIR__ . '/../../templates/post.php'),
    'controllers' => array_fill_keys(Types::templates(), require __DIR__ . '/../../controllers/post.php'),
    // All published posts, newest first, posts of the same time by title.
    'collections' => [
        'posts' => Posts::collect(...),
    ],
    'cacheTypes' => ['blog-file' => FileCache::class],
    // Every page: its URL ends with a slash (SitePage). A post's is
    // /YYYY-MM-DD-slug/ (PostPage); trips and essays add their type line.
    'pageModels' => [
        'default' => SitePage::class,
        ...array_fill_keys(Types::templates(), PostPage::class),
        'trip' => TripPage::class,
        'essay' => EssayPage::class,
    ],
]);
