<?php

use Kirby\Cms\App;
use Kirby\Exception\PermissionException;

/**
 * Panel area "Assistent" (docs/superpowers/specs/2026-10-12-assistent-design.md):
 * Sven writes posts with Claude. Claude proposes, Sven's click applies.
 * Settings in Website › Assistent: the prompt and the model. The key is
 * host config: 'assistantKey' in env.php. Only accounts with the role
 * admin see the area, its API routes and the tab.
 */

require_once __DIR__ . '/claude.php';
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/route.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/tools.php';
require_once __DIR__ . '/chat.php';
require_once __DIR__ . '/apply.php';
require_once __DIR__ . '/log.php';

/** Only accounts with the role admin use the assistant. */
function blog_assistant_allowed(): bool
{
    return kirby()->user()?->role()->id() === 'admin';
}

function blog_assistant_guard(): void
{
    if (!blog_assistant_allowed()) {
        throw new PermissionException(message: 'Der Assistent ist nur für Konten mit der Rolle admin.');
    }
}

/**
 * The site blueprint: tab "Beiträge" for everyone, tab "Assistent" for
 * admins only. Kirby blueprints know no roles, so it is PHP; a file
 * site/blueprints/site.yml would win over it.
 */
function blog_assistant_site_blueprint(): array
{
    return [
        'title' => 'Website',
        'tabs' => [
            'beitraege' => 'tabs/posts',
            'assistent' => blog_assistant_allowed() ? 'tabs/assistant' : false,
        ],
    ];
}

App::plugin('sveneisenschmidt/assistant', [
    'blueprints' => [
        'site' => fn () => blog_assistant_site_blueprint(),
    ],
    'sections' => [
        // Website › Assistent: is the key there, and does Claude answer?
        'assistant-access' => [
            'computed' => [
                'hasKey' => fn () => blog_assistant_key() !== null,
            ],
        ],
    ],
]);
