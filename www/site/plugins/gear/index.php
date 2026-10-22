<?php

use Kirby\Cms\App;
use Website\Gear\Gear;

/**
 * Registers the gear with Kirby: the Panel view "Ausrüstung" with its entry
 * in the menu, and the API route that saves the names and switches of the
 * view. The class is in src/Gear. Website\Blog\Exif takes the names for the
 * lines under a photo.
 */

App::plugin('website/gear', [
    'areas' => [
        'ausruestung' => fn() => [
            'label' => 'Ausrüstung',
            'icon' => 'tag',
            'menu' => true,
            'link' => 'ausruestung',
            'views' => [[
                'pattern' => 'ausruestung',
                'action' => fn() => [
                    'component' => 'k-gear-view',
                    'title' => 'Ausrüstung',
                    'props' => ['rows' => Gear::rows()],
                ],
            ]],
        ],
    ],
    // Kirby lets only a logged-in Panel user call an API route.
    'api' => [
        'routes' => [
            [
                'pattern' => 'gear/save',
                'method' => 'POST',
                'action' => function () {
                    $entries = $this->requestBody()['entries'] ?? [];
                    return ['rows' => Gear::save(is_array($entries) ? $entries : [])];
                },
            ],
        ],
    ],
]);
