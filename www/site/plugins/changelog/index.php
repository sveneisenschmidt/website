<?php

use Kirby\Cms\App;
use Kirby\Cms\Event;
use Website\Changelog\Log;

/**
 * Registers the change log with Kirby: the hooks after every change and the
 * Panel view "Verlauf". The classes are in src/Changelog.
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
