<?php

use Website\Blog\Posts;

/**
 * Search, for the page and for search.json: Kirby falls back to this
 * controller for a representation without its own.
 */
return function () {
    $query = trim((string) get('q'));
    return ['query' => $query, 'results' => Posts::search($query)];
};
