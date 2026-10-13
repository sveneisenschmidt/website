<?php

/**
 * The results of /search.json?q= for search.js: the result list of the
 * search page as HTML (snippets/search-results.php).
 *
 * @var string $query
 * @var Kirby\Cms\Pages $results
 */
echo json_encode(['html' => $query === '' ? '' : snippet('search-results', ['results' => $results], true)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
