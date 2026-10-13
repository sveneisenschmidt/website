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
 * The Panel area "Assistent", where the Panel user writes posts with Claude.
 * Claude proposes each change as a card with buttons to apply or discard
 * it, and only a click of the Panel user applies it. The prompt and the
 * model are set under Website › Assistent
 * (www/site/blueprints/tabs/assistant.yml). The API key is host config:
 * `assistantKey` in www/site/config/env.php. Kirby lets only a logged-in
 * Panel user reach the area and the API routes, and the API routes also
 * require the role admin (`Panel::admin()`). The code is in
 * src/Assistant (namespace `Website\Assistant`, loaded by Composer); this
 * file only registers it with Kirby.
 */

App::plugin('website/assistant', [
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
                    // The usage log (`Log`): one row per answer, without the
                    // text of any message.
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
                    // A pick in a choice comes as its index. The server looks
                    // up the option, so it knows that Claude wrote the text
                    // and its links do not count as links of the Panel user.
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
                'action' => function () {
                    Panel::admin();
                    return Claude::check();
                },
            ],
        ],
    ],
    'sections' => [
        // The section on Website › Assistent shows whether the key is set,
        // and its button checks that Claude answers.
        'assistant-access' => [
            'computed' => [
                'hasKey' => fn() => Claude::key() !== null,
            ],
        ],
    ],
    'hooks' => [
        // The chat ends with the session, so the logout deletes its upload folder.
        'user.logout:before' => function (User $user, ?Session $session = null) {
            try {
                Uploads::remove($session?->get(Uploads::SESSION)['folder'] ?? null);
            } catch (Throwable $e) {
                error_log('blog assistant logout: ' . $e->getMessage());
            }
        },
    ],
]);
