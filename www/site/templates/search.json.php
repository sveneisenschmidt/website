<?php
/**
 * The results of /search.json?q=, for search.js: newest first.
 */
$results = [];
foreach (blog_search(trim((string)get('q'))) as $post) {
    $cover = $post->cover()->toFile();
    $results[] = [
        'url' => $post->url(),
        'title' => $post->title()->value(),
        'date' => $post->date()->toDate('Y-m-d'),
        'thumb' => $cover ? blog_square($cover, 48, 80)->url() : '',
        'thumb2x' => $cover ? blog_square($cover, 96, 80)->url() : '',
    ];
}
echo json_encode($results, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
