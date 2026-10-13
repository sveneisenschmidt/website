<?php

use Kirby\Cms\App;
use Kirby\Cms\Event;
use Website\Changelog\Log;

/**
 * Change log: who changed what, when and where. The code lives in
 * src/Changelog (Log, Diff); this file registers it with Kirby. The Panel
 * shows the log under "Verlauf".
 */

App::plugin('sveneisenschmidt/changelog', [
    'hooks' => [
        'page.*:after' => fn(Event $event) => Log::event($event),
        'file.*:after' => fn(Event $event) => Log::event($event),
        'site.*:after' => fn(Event $event) => Log::event($event),
        'user.*:after' => fn(Event $event) => Log::event($event),
    ],
    'areas' => [
        'verlauf' => fn() => [
            'label' => 'Verlauf',
            'icon' => 'clock',
            'menu' => true,
            'link' => 'verlauf',
            'views' => [[
                'pattern' => 'verlauf',
                'action' => fn() => [
                    'component' => 'k-blog-changelog-view',
                    'title' => 'Verlauf',
                    'props' => ['entries' => Log::entries()],
                ],
            ]],
        ],
    ],
]);
