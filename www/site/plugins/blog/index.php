<?php

use Kirby\Cms\App as Kirby;
use Kirby\Cms\Event;
use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Image\Darkroom;
use Website\Blog\Exif;
use Website\Blog\FileCache;
use Website\Blog\Images;
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
 * Registers the blog with Kirby: hooks, KirbyText tags, the post template
 * and controller, the collection "posts", the cache type, the page models
 * and the thumbs driver. The classes are in src/Blog and src/Models, and
 * Composer loads them.
 */

// config.php selects this thumbs driver: Kirby's GD driver with a
// conversion to sRGB before the resize (SrgbGd).
Darkroom::$types['blog-gd'] = SrgbGd::class;

Kirby::plugin('website/blog', [
    'hooks' => [
        // The EXIF goes into the content file of the image once, on upload.
        // So every backup has it, and a wrong value can be corrected in the
        // Panel. A replaced photo gets the EXIF of the new file. The hooks
        // read the EXIF, remove the metadata from the original (the GPS
        // position included), and then save the fields. The save is a file
        // update, which can render thumbnails (Images::renderAfter()), and
        // after the removal they get the URL of the final file.
        'file.create:after' => function (File $file) {
            $exif = Exif::read($file->root());
            Exif::strip($file);
            Exif::store($file, $exif);
        },
        'file.replace:after' => function (File $newFile) {
            $exif = Exif::read($newFile->root());
            Exif::strip($newFile);
            Exif::store($newFile, $exif, true);
        },
        // A new slug or date gives a published post a new URL, and
        // Redirects::moved() adds a redirect from the old one. After
        // changeSlug, the old page object finds no content file, so the old
        // path takes its date from the new page.
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
        // Images::renderAfter() decides which events give images new
        // thumbnail URLs and renders the thumbnails before the first visit.
        'file.*:after' => fn(Event $event) => Images::renderAfter($event),
        'page.*:after' => fn(Event $event) => Images::renderAfter($event),
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
    'collections' => [
        'posts' => Posts::collect(...),
    ],
    'cacheTypes' => ['blog-file' => FileCache::class],
    // SitePage for every page, PostPage for the posts. TripPage and
    // EssayPage add the type line of a trip and of an essay.
    'pageModels' => [
        'default' => SitePage::class,
        ...array_fill_keys(Types::templates(), PostPage::class),
        'trip' => TripPage::class,
        'essay' => EssayPage::class,
    ],
]);
