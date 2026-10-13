<?php

use Website\Blog\Posts;

/**
 * The search, for the search page and for search.json. Kirby uses this
 * controller for search.json too, because that representation has no
 * controller of its own.
 */
return function () {
    $query = trim((string) get('q'));
    return ['query' => $query, 'results' => Posts::search($query)];
};
