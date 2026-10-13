<?php

namespace Website\Assistant;

use Closure;
use Kirby\Cms\Page;
use Kirby\Panel\Ui\Buttons\ViewButton;
use Website\Blog\Types;

/**
 * What the plugin assistant registers with the Panel calls here: the view
 * "Assistent", its API actions and the button on posts and About. Area and
 * API routes need a login, as all of the Panel.
 */
final class Panel
{
    /**
     * The props of the view "Assistent": the chat, the page it was opened
     * from, and the link to Website › Assistent.
     *
     * @return array<string, mixed>
     */
    public static function props(string $ref): array
    {
        $page = Content::find($ref);
        return Chat::view(Session::load()) + [
            'page' => $page ? ['id' => $page->id(), 'title' => $page->title()->value()] : null,
            'settings' => site()->panel()->url(true) . '?tab=assistent',
        ];
    }

    /**
     * Runs an API action after the uploads are cleaned up. A failed request
     * comes back as {error} for the view.
     *
     * @return array<string, mixed>
     */
    public static function api(Closure $action): array
    {
        Uploads::cleanup();
        try {
            return Chat::view($action());
        } catch (Error $e) {
            return Chat::view(Session::load()) + ['error' => ['kind' => $e->kind, 'text' => $e->getMessage()]];
        }
    }

    /**
     * One uploaded file of the Panel: exactly one per request.
     *
     * @param array<mixed> $files
     */
    public static function upload(array $files): ChatState
    {
        $file = count($files) === 1 ? reset($files) : null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new Error('upload', 'Eine Datei pro Anfrage, und sie kam nicht an. Nichts geändert.');
        }
        Uploads::accept((string) $file['tmp_name'], (string) $file['name']);
        return Session::load();
    }

    /** The button "Assistent" on posts and About: opens the assistant with the page. */
    public static function button(Page $page): ?ViewButton
    {
        $template = $page->intendedTemplate()->name();
        if ($template !== 'about' && !isset(Types::ALL[$template])) {
            return null;
        }
        return new ViewButton(icon: 'robot', text: 'Assistent', link: 'assistent?page=' . rawurlencode($page->id()));
    }
}
