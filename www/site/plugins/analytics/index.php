<?php

use Kirby\Cms\App;
use Website\Analytics\Counter;
use Website\Analytics\Totals;

/**
 * Visitor statistics without cookies, scripts or external services. The
 * code lives in src/Analytics (Counter, Months, Totals); this file
 * registers it with Kirby. The Panel shows the numbers under "Besucher",
 * About shows them in snippets/stats.php.
 */

App::plugin('sveneisenschmidt/analytics', [
    'hooks' => [
        // Before Page::render() reads the page cache, so cached pages count too.
        'route:after' => fn(string $method, mixed $result, bool $final) => Counter::route($method, $result, $final),
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
                    'props' => ['summary' => Totals::summary(30)],
                ],
            ]],
        ],
    ],
]);
