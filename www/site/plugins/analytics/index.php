<?php

use Kirby\Cms\App;
use Website\Analytics\Counter;
use Website\Analytics\Totals;
use Website\Blog\Likes;

/**
 * Registers the visitor statistics with Kirby: the hook that records every
 * request and the Panel view "Besucher", which shows the likes
 * (Website\Blog\Likes) too. The classes are in src/Analytics.
 * About shows the totals through snippets/stats.php.
 */

App::plugin('website/analytics', [
    'hooks' => [
        // Kirby runs route:after before Page::render() reads the page cache,
        // so views of cached pages count too.
        'route:after' => fn(string $path, string $method, mixed $result, bool $final) => Counter::route($path, $method, $result, $final),
    ],
    'areas' => [
        'besucher' => fn() => [
            'label' => 'Besucher',
            'icon' => 'chart',
            'menu' => true,
            'link' => 'besucher',
            'views' => [[
                'pattern' => 'besucher',
                'action' => fn() => [
                    'component' => 'k-blog-analytics-view',
                    'title' => 'Besucher',
                    'props' => ['summary' => Totals::summary(30), 'likes' => Likes::summary(30)],
                ],
            ]],
        ],
    ],
]);
