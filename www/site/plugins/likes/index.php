<?php

use Kirby\Cms\App;
use Website\Likes\Likes;

/**
 * Registers the likes with Kirby: the route that takes a like, the snippet
 * of the like button for templates/post.php, and the Panel view "Likes".
 * The class is in src/Likes. The styles of the button are in site.css.
 */

App::plugin('website/likes', [
    'routes' => [
        // Only a POST request adds a like: a crawler follows every link, but
        // it does not send forms.
        [
            'pattern' => '(\d{4}-\d{2}-\d{2})-([a-z0-9-]+)/like',
            'method' => 'POST',
            'action' => fn(string $date, string $slug) => Likes::route($date, $slug),
        ],
    ],
    'snippets' => [
        'like' => __DIR__ . '/snippets/like.php',
    ],
    'areas' => [
        'likes' => fn() => [
            'label' => 'Likes',
            'icon' => 'heart',
            'menu' => true,
            'link' => 'likes',
            'views' => [[
                'pattern' => 'likes',
                'action' => fn() => [
                    'component' => 'k-likes-view',
                    'title' => 'Likes',
                    'props' => ['likes' => Likes::summary(30)],
                ],
            ]],
        ],
    ],
]);
