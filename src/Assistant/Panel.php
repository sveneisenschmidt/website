<?php

namespace Website\Assistant;

use Closure;
use Kirby\Cms\Page;
use Kirby\Exception\PermissionException;
use Kirby\Panel\Ui\Buttons\ViewButton;
use Website\Blog\Types;

/**
 * The Panel side of the assistant. The plugin
 * www/site/plugins/assistant/index.php registers the view "Assistent", its
 * API routes and the button "Assistent" on posts and About, and each of them
 * calls a method here. Kirby lets only a logged-in Panel user reach the view
 * and the API routes.
 */
final class Panel
{
    /**
     * The props of the Vue component `k-blog-assistant-view` in index.js: the
     * chat, the page whose button opened the view, and the link to the
     * settings under Website › Assistent.
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
     * Runs one API action and returns the chat for the view. Every request
     * first deletes old uploads and chat folders (`Uploads::cleanup()`). An
     * `Error` comes back as the key "error" next to the stored chat, so the
     * view can show the error and keep the chat.
     *
     * @return array<string, mixed>
     */
    public static function api(Closure $action): array
    {
        self::admin();
        Uploads::cleanup();
        try {
            return Chat::view($action());
        } catch (Error $e) {
            return Chat::view(Session::load()) + ['error' => ['kind' => $e->kind, 'text' => $e->getMessage()]];
        }
    }

    /**
     * Only an admin may use the assistant: it writes to every post and
     * spends money at Anthropic. The site has the role admin only, so the
     * check matters when a second role is added.
     */
    public static function admin(): void
    {
        if (kirby()->user()?->isAdmin() !== true) {
            throw new PermissionException('Only an admin can use the assistant.');
        }
    }

    /**
     * Accepts the file of one upload request. index.js sends one request per
     * file, so a request with no file or with several files is refused.
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

    /**
     * The button "Assistent" in the header of a post or of About; other pages
     * get none. It opens the assistant, and the next message tells Claude
     * which page it came from.
     */
    public static function button(Page $page): ?ViewButton
    {
        $template = $page->intendedTemplate()->name();
        if ($template !== 'about' && !isset(Types::ALL[$template])) {
            return null;
        }
        return new ViewButton(icon: 'robot', text: 'Assistent', link: 'assistent?page=' . rawurlencode($page->id()));
    }
}
