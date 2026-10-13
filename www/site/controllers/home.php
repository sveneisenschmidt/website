<?php

use Website\Blog\Posts;

/**
 * The front page: the newest pick large, the twelve before it as tiles.
 */
return function () {
    $picks = Posts::picks();
    return ['lead' => $picks->first(), 'tiles' => $picks->offset(1)->limit(12)];
};
