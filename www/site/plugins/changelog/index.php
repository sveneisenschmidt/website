<?php

use Kirby\Cms\App;
use Kirby\Cms\Event;
use Kirby\Cms\File;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Cms\User;
use Kirby\Data\Yaml;
use Kirby\Filesystem\F;
use Kirby\Toolkit\Str;
use Kirby\Uuid\Uuid;

/**
 * Change log: who changed what, when and where.
 * Every change to a page, file, the site or a user adds one JSON line to
 * content/.changelog/<year>-<month>.jsonl. Kirby ignores dot folders in
 * content, the backup includes all of content and the deploy excludes it,
 * so the log is state: it comes back with every restore.
 * The Panel shows it under "Verlauf".
 */

const BLOG_CHANGELOG_ACTIONS = [
    'page' => ['create', 'update', 'delete', 'duplicate', 'changeStatus', 'changeTitle', 'changeSlug', 'changeNum', 'changeTemplate'],
    'file' => ['create', 'update', 'delete', 'replace', 'changeName', 'changeSort'],
    'site' => ['update', 'changeTitle'],
    'user' => ['create', 'delete', 'changeEmail', 'changeName', 'changeRole', 'changePassword'],
];

/** Fields that are not content: never logged. */
const BLOG_CHANGELOG_SKIP = ['uuid', 'password'];

const BLOG_CHANGELOG_MAX = 200;

function blog_changelog_dir(): string
{
    return kirby()->root('content') . '/.changelog';
}

/** Hook handler for page.*:after, file.*:after, site.*:after, user.*:after. */
function blog_changelog_event(Event $event): void
{
    $type = $event->type();
    $action = $event->action();
    if (!in_array($action, BLOG_CHANGELOG_ACTIONS[$type] ?? [], true)) {
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
        $changes = $old instanceof ModelWithContent && $type !== 'user' ? blog_changelog_diff($old, $new) : [];
        // A save without a real change is no entry, nor a sort step of publishing.
        if (in_array($action, ['update', 'changeNum'], true) && $changes === []) {
            return;
        }
        blog_changelog_write([
            'action' => "$type.$action",
            'type' => $type,
            'id' => blog_changelog_id($new),
            'title' => blog_changelog_title($new),
            // "Foto", "Reise", "Notiz", "Essay": the Panel view says "Foto angelegt", not "Seite angelegt".
            'kind' => $new instanceof Page ? blog_changelog_kind($new) : null,
            'link' => $action === 'delete' ? null : $new->panel()->url(true),
            'changes' => $changes,
        ]);
    } catch (Throwable $e) {
        // A log entry must never break a save.
        error_log('blog changelog: ' . $e->getMessage());
    }
}

/** Appends one entry. Parallel requests append under a lock (F::write uses LOCK_EX). */
function blog_changelog_write(array $entry): void
{
    $user = kirby()->user();
    $person = $user === null || $user->isKirby() ? null : $user;
    $entry = [
        'time' => date('c'),
        'user' => $person?->email(),
        'name' => $person ? ($person->name()->or($person->email())->value()) : null,
        ...$entry,
    ];
    $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    F::write(blog_changelog_dir() . '/' . date('Y-m') . '.jsonl', $line, true);
}

function blog_changelog_id(ModelWithContent $model): string
{
    return match (true) {
        $model instanceof Site => 'site',
        $model instanceof User => $model->email(),
        default => $model->id(),
    };
}

function blog_changelog_title(ModelWithContent $model): string
{
    return match (true) {
        $model instanceof Site => 'Website',
        $model instanceof User => $model->name()->or($model->email())->value(),
        $model instanceof File => $model->parent()->title()->value() . ' · ' . $model->filename(),
        default => $model->title()->value(),
    };
}

/** The post types Sven writes. Other pages stay "Seite". */
function blog_changelog_kind(Page $page): ?string
{
    return match ($page->intendedTemplate()->name()) {
        'photo' => 'Foto',
        'trip' => 'Reise',
        'note' => 'Notiz',
        'essay' => 'Essay',
        default => null,
    };
}

/** A page status as the Panel names it for this page: "Entwurf", "Veröffentlicht". */
function blog_changelog_status(Page $page, string $status): string
{
    $label = $page->blueprint()->status()[$status]['label'] ?? null;
    return is_string($label) && $label !== '' ? $label : match ($status) {
        'draft' => 'Entwurf',
        default => 'Veröffentlicht',
    };
}

/** Changed content fields with label, old and new value (shortened). */
function blog_changelog_diff(ModelWithContent $old, ModelWithContent $new): array
{
    // A status, sort or slug change moves the page folder: the old object then
    // reads no content, and every field would look new.
    $moved = $old instanceof Page && $new instanceof Page && ($old->status() !== $new->status() || $old->num() !== $new->num() || $old->slug() !== $new->slug());
    $a = $moved ? [] : $old->content()->toArray();
    $b = $moved ? [] : $new->content()->toArray();
    $changes = [];
    foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $key) {
        if (in_array($key, BLOG_CHANGELOG_SKIP, true)) {
            continue;
        }
        $from = (string)($a[$key] ?? '');
        $to = (string)($b[$key] ?? '');
        if ($from === $to) {
            continue;
        }
        // Lists (structure, files, pages): the rows that went and came.
        $fromList = blog_changelog_list($from);
        $toList = blog_changelog_list($to);
        if ($fromList !== null && $toList !== null) {
            $removed = array_values(array_diff($fromList, $toList));
            $added = array_values(array_diff($toList, $fromList));
            if ($removed === [] && $added === []) {
                continue;
            }
            [$from, $to] = [implode(', ', $removed), implode(', ', $added)];
        }
        $changes[] = [
            'field' => $key,
            'label' => blog_changelog_label($new, $key),
            'old' => Str::short($from, BLOG_CHANGELOG_MAX),
            'new' => Str::short($to, BLOG_CHANGELOG_MAX),
        ];
    }
    // Slug and status are no content fields.
    if ($old instanceof Page && $new instanceof Page) {
        if ($old->slug() !== $new->slug()) {
            $changes[] = ['field' => 'slug', 'label' => 'URL-Anhang', 'old' => $old->slug(), 'new' => $new->slug()];
        }
        // Publishing goes draft, unlisted, listed: Kirby logs the last step as a
        // sort change. Two states with the same Panel name are no change.
        [$from, $to] = [blog_changelog_status($old, $old->status()), blog_changelog_status($new, $new->status())];
        if ($from !== $to) {
            $changes[] = ['field' => 'status', 'label' => 'Status', 'old' => $from, 'new' => $to];
        }
        // A status change sets or clears the number itself: not a second change.
        if ($old->num() !== $new->num() && $old->status() === $new->status()) {
            $changes[] = ['field' => 'num', 'label' => 'Reihenfolge', 'old' => (string)$old->num(), 'new' => (string)$new->num()];
        }
    }
    if ($old instanceof File && $new instanceof File && $old->filename() !== $new->filename()) {
        $changes[] = ['field' => 'filename', 'label' => 'Dateiname', 'old' => $old->filename(), 'new' => $new->filename()];
    }
    return $changes;
}

/**
 * A YAML list as readable items, or null for any other value.
 * A file or page reference (UUID or id) becomes its name, a structure row
 * its first value: a reference written as id and later as UUID is the same item.
 */
function blog_changelog_list(string $value): ?array
{
    if ($value !== '' && !str_starts_with($value, '-')) {
        return null;
    }
    try {
        $items = Yaml::decode($value);
    } catch (Throwable) {
        return null;
    }
    if (!array_is_list($items)) {
        return null;
    }
    return array_map(function ($item) {
        if (is_array($item)) {
            $first = array_filter($item, fn ($v) => is_scalar($v) && (string)$v !== '');
            return (string)(reset($first) ?: '');
        }
        $item = (string)$item;
        $model = Uuid::is($item) ? Uuid::for($item)?->model() : (kirby()->file($item) ?? kirby()->page($item));
        return match (true) {
            $model instanceof File => blog_changelog_title($model),
            $model instanceof Page => $model->title()->value(),
            default => $item,
        };
    }, $items);
}

/** The field label from the blueprint, or the field name. */
function blog_changelog_label(ModelWithContent $model, string $key): string
{
    $label = $model->blueprint()->field($key)['label'] ?? null;
    if (is_array($label)) {
        $label = $label['de'] ?? $label['en'] ?? reset($label);
    }
    return is_string($label) && $label !== '' ? $label : ucfirst($key);
}

/** The newest entries first, at most $limit. */
function blog_changelog_entries(int $limit = 500): array
{
    $files = glob(blog_changelog_dir() . '/*.jsonl') ?: [];
    rsort($files);
    $entries = [];
    foreach ($files as $file) {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach (array_reverse($lines) as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry)) {
                $entries[] = $entry;
                if (count($entries) >= $limit) {
                    return $entries;
                }
            }
        }
    }
    return $entries;
}

App::plugin('sveneisenschmidt/changelog', [
    'hooks' => [
        'page.*:after' => fn (Event $event) => blog_changelog_event($event),
        'file.*:after' => fn (Event $event) => blog_changelog_event($event),
        'site.*:after' => fn (Event $event) => blog_changelog_event($event),
        'user.*:after' => fn (Event $event) => blog_changelog_event($event),
    ],
    'areas' => [
        'verlauf' => fn () => [
            'label' => 'Verlauf',
            'icon' => 'clock',
            'menu' => true,
            'link' => 'verlauf',
            'views' => [[
                'pattern' => 'verlauf',
                'action' => fn () => [
                    'component' => 'k-blog-changelog-view',
                    'title' => 'Verlauf',
                    'props' => ['entries' => blog_changelog_entries()],
                ],
            ]],
        ],
    ],
]);
