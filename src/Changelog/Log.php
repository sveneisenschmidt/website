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
 * Change log: who changed what, when and where.
 * Every change to a page, file, the site or a user adds one JSON line to
 * content/.changelog/<year>-<month>.jsonl. Kirby ignores dot folders in
 * content, the backup includes all of content and the deploy excludes it,
 * so the log is state: it comes back with every restore.
 * The Panel shows it under "Verlauf".
 */
final class Log
{
    /** The events that make an entry, by model type. */
    public const ACTIONS = [
        'page' => ['create', 'update', 'delete', 'duplicate', 'changeStatus', 'changeTitle', 'changeSlug', 'changeNum', 'changeTemplate'],
        'file' => ['create', 'update', 'delete', 'replace', 'changeName', 'changeSort'],
        'site' => ['update', 'changeTitle'],
        'user' => ['create', 'delete', 'changeEmail', 'changeName', 'changeRole', 'changePassword'],
    ];

    public static function dir(): string
    {
        return kirby()->root('content') . '/.changelog';
    }

    /** Hook handler for page.*:after, file.*:after, site.*:after, user.*:after. */
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
            // Posts are numbered by their date: a new date moves the number, and
            // the date change is the entry. The number alone says nothing.
            if ($action === 'changeNum' && $new instanceof Page && $new->blueprint()->num() === 'date') {
                return;
            }
            $changes = $old instanceof ModelWithContent && $type !== 'user' ? Diff::changes($old, $new) : [];
            // A save without a real change is no entry, nor a sort step of publishing.
            if (in_array($action, ['update', 'changeNum'], true) && $changes === []) {
                return;
            }
            self::write([
                'action' => "$type.$action",
                'type' => $type,
                'id' => self::id($new),
                'title' => self::title($new),
                // "Foto", "Reise", "Notiz", "Essay": the Panel view says "Foto angelegt", not "Seite angelegt".
                'kind' => $new instanceof Page ? self::kind($new) : null,
                'link' => $action === 'delete' ? null : $new->panel()->url(true),
                'changes' => $changes,
            ]);
        } catch (Throwable $e) {
            // A log entry must never break a save.
            error_log('blog changelog: ' . $e->getMessage());
        }
    }

    /**
     * Appends one entry (Website\Blog\Jsonl).
     *
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

    /** What the entry names in "Wo": a page title, a file under its page, a person. */
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

    /** The post types Sven writes. Other pages stay "Seite". */
    private static function kind(Page $page): ?string
    {
        return Types::ALL[$page->intendedTemplate()->name()]['label'] ?? null;
    }
}
