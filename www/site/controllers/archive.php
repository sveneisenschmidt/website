<?php

use Kirby\Cms\Page;
use Website\Blog\Posts;

/**
 * The archive and its four streams. Photos are the photo posts and the
 * trips with a cover.
 */
return function (Page $page) {
    $stream = $page->stream()->or('all')->value();
    $posts = Posts::all();
    if ($stream === 'photos') {
        $posts = $posts->filter(fn($post) => $post->parent()->id() === 'photos' || ($post->parent()->id() === 'trips' && $post->cover()->isNotEmpty()));
    } elseif ($stream !== 'all') {
        $posts = $posts->filter(fn($post) => $post->parent()->id() === $stream);
    }
    return ['stream' => $stream, 'posts' => $posts];
};
