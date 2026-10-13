<?php

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\User;
use Kirby\Panel\Ui\Buttons\ViewButton;
use Kirby\Session\Session;
use Website\Blog\Types;

/**
 * Panel area "Assistent": Sven writes posts with Claude. Claude proposes, Sven's click applies.
 * Settings in Website › Assistent: the prompt and the model. The key is
 * host config: 'assistantKey' in env.php. Area and API routes need a
 * login, as all of the Panel.
 */

require_once __DIR__ . '/claude.php';
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/content.php';
require_once __DIR__ . '/tools.php';
require_once __DIR__ . '/propose.php';
require_once __DIR__ . '/chat.php';
require_once __DIR__ . '/apply.php';
require_once __DIR__ . '/log.php';

/**
 * Runs an API action after the uploads are cleaned up. A failed request
 * comes back as {error} for the view.
 */
function blog_assistant_api(Closure $action): array
{
    blog_uploads_cleanup();
    try {
        return blog_assistant_view($action());
    } catch (BlogAssistantError $e) {
        return blog_assistant_view(blog_assistant_load()) + ['error' => ['kind' => $e->kind, 'text' => $e->getMessage()]];
    }
}

/** One uploaded file of the Panel: exactly one per request. */
function blog_assistant_upload(array $files): array
{
    $file = count($files) === 1 ? reset($files) : null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
        throw new BlogAssistantError('upload', 'Eine Datei pro Anfrage, und sie kam nicht an. Nichts geändert.');
    }
    blog_uploads_accept((string) $file['tmp_name'], (string) $file['name']);
    return blog_assistant_load();
}

/** The button "Assistent" on posts and About: opens the assistant with the page. */
function blog_assistant_button(Page $page): ?ViewButton
{
    $template = $page->intendedTemplate()->name();
    if ($template !== 'about' && !isset(Types::ALL[$template])) {
        return null;
    }
    return new ViewButton(icon: 'robot', text: 'Assistent', link: 'assistent?page=' . rawurlencode($page->id()));
}

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
                    'action' => function () {
                        $page = blog_assistant_find((string) get('page', ''));
                        return [
                            'component' => 'k-blog-assistant-view',
                            'title' => 'Assistent',
                            'props' => blog_assistant_view(blog_assistant_load()) + [
                                'page' => $page ? ['id' => $page->id(), 'title' => $page->title()->value()] : null,
                                'settings' => site()->panel()->url(true) . '?tab=assistent',
                            ],
                        ];
                    },
                ], [
                    // Protokoll: who asked when, how long, which tools; no content.
                    'pattern' => 'assistent/protokoll',
                    'action' => fn() => [
                        'component' => 'k-blog-assistant-log-view',
                        'title' => 'Protokoll · Assistent',
                        'breadcrumb' => [['label' => 'Protokoll', 'link' => 'assistent/protokoll']],
                        'props' => blog_assistant_log_view(),
                    ],
                ]],
                'buttons' => ['page.assistant' => fn(Page $page) => blog_assistant_button($page)],
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
                    return blog_assistant_api(fn() => isset($body['choice'])
                        ? blog_assistant_choose((int) $body['choice'], $body['page'] ?? null)
                        : blog_assistant_send((string) ($body['text'] ?? ''), $body['page'] ?? null));
                },
            ],
            [
                'pattern' => 'blog-assistant/next',
                'method' => 'POST',
                'action' => fn() => blog_assistant_api(fn() => blog_assistant_next()),
            ],
            [
                'pattern' => 'blog-assistant/stop',
                'method' => 'POST',
                'action' => fn() => blog_assistant_api(fn() => blog_assistant_stop()),
            ],
            [
                'pattern' => 'blog-assistant/reset',
                'method' => 'POST',
                'action' => fn() => blog_assistant_api(fn() => blog_assistant_reset()),
            ],
            [
                'pattern' => 'blog-assistant/decide',
                'method' => 'POST',
                'action' => function () {
                    $body = $this->requestBody();
                    $version = isset($body['version']) ? (int) $body['version'] : null;
                    return blog_assistant_api(fn() => blog_assistant_decide((string) ($body['id'] ?? ''), (string) ($body['action'] ?? ''), $version));
                },
            ],
            [
                'pattern' => 'blog-assistant/upload',
                'method' => 'POST',
                'action' => function () {
                    $files = $this->requestFiles() ?? [];
                    return blog_assistant_api(fn() => blog_assistant_upload($files));
                },
            ],
            [
                'pattern' => 'blog-assistant/check',
                'method' => 'POST',
                'action' => fn() => blog_assistant_check(),
            ],
        ],
    ],
    'sections' => [
        // Website › Assistent: is the key there, and does Claude answer?
        'assistant-access' => [
            'computed' => [
                'hasKey' => fn() => blog_assistant_key() !== null,
            ],
        ],
    ],
    'hooks' => [
        // The chat ends with the session: its uploads go at logout.
        'user.logout:before' => function (User $user, ?Session $session = null) {
            try {
                blog_uploads_remove($session?->get(BLOG_UPLOAD_SESSION)['folder'] ?? null);
            } catch (Throwable $e) {
                error_log('blog assistant logout: ' . $e->getMessage());
            }
        },
    ],
]);
