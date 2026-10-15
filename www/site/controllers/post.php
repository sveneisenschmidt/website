<?php

use Website\Blog\Likes;
use Website\Models\PostPage;

/**
 * The variables of templates/post.php for all four post types; plugins/blog
 * registers the controller for each. `section` is the archive sub-page that
 * the label and the type line link to, `label` the text above the title,
 * `type` the type line after the date, `likes` the number of likes.
 * `liked` is true after a like of this IP address (Likes::route()). Only a
 * request with ?liked checks the address: Kirby never caches such a
 * request, and the cached page must be the same for every visitor.
 */
return function (PostPage $page) {
    return [
        'section' => $page->parent()->slug(),
        'label' => $page->pick()->toBool() ? 'Featured photo' : $page->intendedTemplate()->name(),
        'type' => $page->typeLine(false),
        'likes' => Likes::count($page),
        'liked' => $page->kirby()->request()->query()->get('liked') !== null && Likes::has($page, (string) ($_SERVER['REMOTE_ADDR'] ?? '')),
    ];
};
