<?php

use Website\Blog\Posts;

/**
 * The front page: the newest featured post (field `pick`) as a large
 * picture, the next twelve as tiles.
 */
return function () {
    $picks = Posts::picks();
    return ['lead' => $picks->first(), 'tiles' => $picks->offset(1)->limit(12)];
};
