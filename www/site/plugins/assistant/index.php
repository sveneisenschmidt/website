<?php

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\User;
use Kirby\Session\Session;
use Website\Assistant\Apply;
use Website\Assistant\Chat;
use Website\Assistant\Claude;
use Website\Assistant\Log;
use Website\Assistant\Panel;
use Website\Assistant\Uploads;

/**
 * Panel area "Assistent": Sven writes posts with Claude. Claude proposes, Sven's click applies.
 * Settings in Website › Assistent: the prompt and the model. The key is
 * host config: 'assistantKey' in env.php. Area and API routes need a
 * login, as all of the Panel. The code lives in src/Assistant
 * (Website\Assistant), loaded by Composer; this file registers it with Kirby.
 */

App::plugin('sveneisenschmidt/assistant', [
    'areas' => [
        'assistent' => function () {
            return [
                'label' => 'Assistent',
                'icon' => 'robot',
                'menu' => true,
                'link' => 'assistent',
                'views' => [[
                    'pattern' => 'assistent',
                    'action' => fn() => [
                        'component' => 'k-blog-assistant-view',
                        'title' => 'Assistent',
                        'props' => Panel::props((string) get('page', '')),
                    ],
                ], [
                    // Protokoll: who asked when, how long, which tools; no content.
                    'pattern' => 'assistent/protokoll',
                    'action' => fn() => [
                        'component' => 'k-blog-assistant-log-view',
                        'title' => 'Protokoll · Assistent',
                        'breadcrumb' => [['label' => 'Protokoll', 'link' => 'assistent/protokoll']],
                        'props' => Log::view(),
                    ],
                ]],
                'buttons' => ['page.assistant' => fn(Page $page) => Panel::button($page)],
            ];
        },
    ],
    'api' => [
        'routes' => [
            [
                'pattern' => 'blog-assistant/send',
                'method' => 'POST',
                'action' => function () {
                    $body = $this->requestBody();
                    // A pick in a choice comes as its number, never as text.
                    return Panel::api(fn() => isset($body['choice'])
                        ? Chat::choose((int) $body['choice'], $body['page'] ?? null)
                        : Chat::send((string) ($body['text'] ?? ''), $body['page'] ?? null));
                },
            ],
            [
                'pattern' => 'blog-assistant/next',
                'method' => 'POST',
                'action' => fn() => Panel::api(fn() => Chat::next()),
            ],
            [
                'pattern' => 'blog-assistant/stop',
                'method' => 'POST',
                'action' => fn() => Panel::api(fn() => Chat::stop()),
            ],
            [
                'pattern' => 'blog-assistant/reset',
                'method' => 'POST',
                'action' => fn() => Panel::api(fn() => Chat::reset()),
            ],
            [
                'pattern' => 'blog-assistant/decide',
                'method' => 'POST',
                'action' => function () {
                    $body = $this->requestBody();
                    $version = isset($body['version']) ? (int) $body['version'] : null;
                    return Panel::api(fn() => Apply::decide((string) ($body['id'] ?? ''), (string) ($body['action'] ?? ''), $version));
                },
            ],
            [
                'pattern' => 'blog-assistant/upload',
                'method' => 'POST',
                'action' => function () {
                    $files = $this->requestFiles() ?? [];
                    return Panel::api(fn() => Panel::upload($files));
                },
            ],
            [
                'pattern' => 'blog-assistant/check',
                'method' => 'POST',
                'action' => fn() => Claude::check(),
            ],
        ],
    ],
    'sections' => [
        // Website › Assistent: is the key there, and does Claude answer?
        'assistant-access' => [
            'computed' => [
                'hasKey' => fn() => Claude::key() !== null,
            ],
        ],
    ],
    'hooks' => [
        // The chat ends with the session: its uploads go at logout.
        'user.logout:before' => function (User $user, ?Session $session = null) {
            try {
                Uploads::remove($session?->get(Uploads::SESSION)['folder'] ?? null);
            } catch (Throwable $e) {
                error_log('blog assistant logout: ' . $e->getMessage());
            }
        },
    ],
]);
