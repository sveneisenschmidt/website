<?php

use Kirby\Cms\Page;
use Website\Blog\Posts;

/**
 * The archive page and its sub-pages. The field `stream` names the posts a
 * page lists: all, photos, trips, notes or essays. The photos page lists
 * the photo posts and the trip posts with a cover.
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
