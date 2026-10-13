<?php

namespace Website\Changelog;

use Kirby\Cms\Event;
use Kirby\Cms\File;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Cms\User;
use Throwable;
use Website\Blog\Jsonl;
use Website\Blog\Types;

/**
 * The change log: who changed what, when and where. Every
 * change to a page, a file, the site or a user adds one JSON line to
 * content/.changelog/<year>-<month>.jsonl. So does every Panel login and
 * every failed login. Kirby ignores folders in
 * content/ whose name starts with a dot, so the log never shows as a page.
 * The backup includes all of content/, and the deploy never overwrites it,
 * so a restore brings the log back. The Panel view "Verlauf"
 * (plugins/changelog) shows it.
 */
final class Log
{
    /** The events that make an entry, by model type. */
    public const ACTIONS = [
        'page' => ['create', 'update', 'delete', 'duplicate', 'changeStatus', 'changeTitle', 'changeSlug', 'changeNum', 'changeTemplate'],
        'file' => ['create', 'update', 'delete', 'replace', 'changeName', 'changeSort'],
        'site' => ['update', 'changeTitle'],
        'user' => ['create', 'delete', 'changeEmail', 'changeName', 'changeRole', 'changePassword', 'login'],
    ];

    public static function dir(): string
    {
        return kirby()->root('content') . '/.changelog';
    }

    /** Handler for the hooks page.*:after, file.*:after, site.*:after and user.*:after (plugins/changelog). */
    public static function event(Event $event): void
    {
        $type = $event->type();
        $action = $event->action();
        if (!in_array($action, self::ACTIONS[$type] ?? [], true)) {
            return;
        }
        try {
            $args = $event->arguments();
            $new = $args['new' . ucfirst($type)] ?? $args[$type] ?? $args['duplicatePage'] ?? null;
            $old = $args['old' . ucfirst($type)] ?? null;
            if (!$new instanceof ModelWithContent) {
                return;
            }
            // The post blueprints set `num: date`, so the sort number follows
            // the date. The update event logs the date change; the number
            // change would only repeat it.
            if ($action === 'changeNum' && $new instanceof Page && $new->blueprint()->num() === 'date') {
                return;
            }
            $changes = $old instanceof ModelWithContent && $type !== 'user' ? Diff::changes($old, $new) : [];
            // Kirby fires update for a save without a change, and changeNum
            // while it publishes a draft (see Diff::changes()). Neither makes
            // an entry when Diff finds no change.
            if (in_array($action, ['update', 'changeNum'], true) && $changes === []) {
                return;
            }
            self::write([
                'action' => "$type.$action",
                'type' => $type,
                'id' => self::id($new),
                'title' => self::title($new),
                // The German name of the post type ("Foto", "Reise", "Notiz",
                // "Essay"). The Panel view then shows "Foto angelegt" (photo
                // created) in place of "Seite angelegt" (page created).
                'kind' => $new instanceof Page ? self::kind($new) : null,
                'link' => $action === 'delete' ? null : $new->panel()->url(true),
                'changes' => $changes,
            ]);
        } catch (Throwable $e) {
            // An error in the log must not fail the save.
            error_log('blog changelog: ' . $e->getMessage());
        }
    }

    /**
     * Handler for the hook user.login:failed (plugins/changelog): a wrong
     * email, password or code. Kirby also fires the hook for every attempt
     * while it blocks the IP address or the email. Those attempts make no
     * entry, so one IP address adds at most auth.trials entries (Kirby: 10)
     * per auth.timeout (Kirby: one hour).
     */
    public static function failedLogin(?string $email): void
    {
        try {
            if (kirby()->auth()->isBlocked($email ?? '')) {
                return;
            }
            // The email is the text from the login form. No valid address is
            // longer than 254 characters, so a longer text cannot fill the log.
            $email = mb_substr($email ?? '', 0, 254);
            self::write([
                'action' => 'user.login:failed',
                'type' => 'user',
                'id' => $email,
                'title' => $email,
                'kind' => null,
                'link' => null,
                'changes' => [],
            ]);
        } catch (Throwable $e) {
            error_log('blog changelog: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $entry
     */
    public static function write(array $entry): void
    {
        Jsonl::append(self::dir(), $entry);
    }

    /**
     * The newest entries first, at most $limit.
     *
     * @return list<array<string, mixed>>
     */
    public static function entries(int $limit = 500): array
    {
        return Jsonl::read(self::dir(), $limit);
    }

    /** The text in the column "Wo" (where) of the Panel view: the site, a page title, a file with its page, or a person. */
    public static function title(ModelWithContent $model): string
    {
        return match (true) {
            $model instanceof Site => 'Website',
            $model instanceof User => $model->name()->or($model->email())->value(),
            $model instanceof File => $model->parent()->title()->value() . ' · ' . $model->filename(),
            default => $model->title()->value(),
        };
    }

    private static function id(ModelWithContent $model): string
    {
        return match (true) {
            $model instanceof Site => 'site',
            $model instanceof User => $model->email(),
            default => $model->id(),
        };
    }

    /** The German name of a post type, or null for every other page, which the Panel view calls "Seite" (page). */
    private static function kind(Page $page): ?string
    {
        return Types::ALL[$page->intendedTemplate()->name()]['label'] ?? null;
    }
}
