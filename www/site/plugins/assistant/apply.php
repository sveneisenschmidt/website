<?php

use Kirby\Cms\File;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use Kirby\Data\Yaml;
use Kirby\Filesystem\F;

/**
 * Sven's click on a card. Only here does anything change, and always as
 * his account, so "Verlauf" logs it like his own edit (spec, "Karten und
 * Klick"). A draft gets the change at once. A published post and About get
 * it as Kirby's unsaved changes: live only with his "Speichern" in the
 * Panel, and he can discard it there. The next message tells Claude.
 */

/** A card that no longer fits the content: nothing changed. The message is for the Panel, the reason for Claude. */
final class BlogAssistantStale extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}

/** Applies or discards a proposal; $version picks one of several versions. Returns the chat. */
function blog_assistant_decide(string $id, string $action, ?int $version = null): array
{
    if (!in_array($action, ['apply', 'discard'], true)) {
        throw new InvalidArgumentException('Unbekannte Entscheidung');
    }
    // A second click on the same card waits for the first and then sees its decision.
    blog_assistant_lock();
    $chat = blog_assistant_load();
    $proposal = $chat['proposals'][$id] ?? throw new InvalidArgumentException('Vorschlag nicht gefunden');
    // Decisions live in their own session key: a round that runs meanwhile
    // stores the chat, never the decisions.
    $decisions = blog_assistant_decisions();
    // A failed change may be tried again; a decided one stays.
    if (!in_array($decisions[$id]['state'] ?? 'open', ['open', 'failed'], true)) {
        return blog_assistant_load();
    }
    $card = 'Card "' . $proposal['title'] . '"';
    if ($action === 'discard') {
        $decision = ['state' => 'discarded'];
        blog_assistant_note("($card: discarded by the user.)");
    } else {
        try {
            $result = blog_assistant_apply($proposal, $version);
            $decision = ['state' => 'done', 'result' => $result];
            blog_assistant_note("($card: applied by the user." . $result['claude'] . ')');
        } catch (BlogAssistantStale $e) {
            $decision = ['state' => 'stale', 'warn' => $e->getMessage()];
            blog_assistant_note("($card: not applied, {$e->reason}. Nothing changed.)");
        } catch (Throwable $e) {
            $decision = ['state' => 'failed'];
            error_log('blog assistant: ' . $e->getMessage());
            blog_assistant_note("($card: failed, nothing changed. The user may try again.)");
        }
    }
    $decisions[$id] = $decision;
    kirby()->session()->set(BLOG_ASSISTANT_SESSION . '.decisions', $decisions);
    blog_assistant_log_write(['type' => 'decision', 'chat' => $chat['id'] ?? null, 'kind' => $proposal['kind'], 'decision' => $decision['state']]);
    return blog_assistant_load();
}

/** Runs the change of a card. Returns what the card shows after it, and the note for Claude. */
function blog_assistant_apply(array $proposal, ?int $version): array
{
    $in = $proposal['input'];
    return match ($proposal['kind']) {
        'post' => blog_assistant_apply_post($in),
        'field' => blog_assistant_apply_field($in),
        'edit' => blog_assistant_apply_edit($in, $version ?? 0),
        'file' => blog_assistant_apply_file($in),
        'route' => blog_assistant_apply_route($in),
        default => throw new RuntimeException('Unbekannter Vorschlag'),
    };
}

/** True for a draft and the files of a draft. */
function blog_assistant_is_draft(ModelWithContent $model): bool
{
    $page = $model instanceof File ? $model->page() : $model;
    return $page instanceof Page && $page->isDraft();
}

/**
 * Writes fields as the spec asks: into a draft at once, into a published
 * post, About and their files only as Kirby's unsaved changes.
 */
function blog_assistant_write(ModelWithContent $model, array $fields): ModelWithContent
{
    $changes = $model->version('changes');
    if (blog_assistant_is_draft($model)) {
        $model = $model->update($fields);
        // Unsaved changes in the Panel get the new values too, so his next "Speichern" keeps them.
        $changes = $model->version('changes');
        if ($changes->exists()) {
            $changes->update($fields);
            if ($changes->isIdentical($model->version('latest'))) {
                $changes->delete();
            }
        }
        return $model;
    }
    if ($changes->exists()) {
        $changes->update($fields);
    } else {
        $changes->create([...$model->version('latest')->read(), ...$fields]);
    }
    return $model;
}

/** What a card shows after the click, and the note for Claude. */
function blog_assistant_result(ModelWithContent $model): array
{
    $draft = blog_assistant_is_draft($model);
    return [
        'text' => $draft ? 'Im Entwurf gespeichert.' : 'In den Änderungen gespeichert. Live erst mit deinem Speichern.',
        'link' => $model->panel()->url(true),
        'linkText' => $model instanceof File ? 'Datei öffnen' : 'Öffnen',
        'claude' => $draft ? '' : ' It waits in the unsaved changes until the user saves in the Panel.',
    ];
}

/** The card "Feld": the whole field, while it still holds what the card showed before. */
function blog_assistant_apply_field(array $in): array
{
    $page = blog_assistant_find($in['page']) ?? throw new RuntimeException('Seite nicht gefunden');
    if (blog_assistant_current($page, $in['field']) !== $in['before']) {
        throw new BlogAssistantStale('Das Feld wurde seit dem Vorschlag bearbeitet. Nichts geändert.', 'the field changed since the proposal');
    }
    return blog_assistant_result(blog_assistant_write($page, [$in['field'] => $in['value']]));
}

/** The card "Passage": the version Sven picked, while the passage still occurs exactly once. */
function blog_assistant_apply_edit(array $in, int $version): array
{
    $page = blog_assistant_find($in['page']) ?? throw new RuntimeException('Seite nicht gefunden');
    $text = $in['versions'][$version] ?? throw new InvalidArgumentException('Fassung nicht gefunden');
    $current = blog_assistant_current($page, $in['field']);
    if (substr_count($current, $in['passage']) !== 1) {
        throw new BlogAssistantStale('Die Passage steht nicht mehr genau einmal im Feld. Nichts geändert.', 'the passage no longer occurs exactly once');
    }
    $result = blog_assistant_result(blog_assistant_write($page, [$in['field'] => str_replace($in['passage'], $text, $current)]));
    $result['claude'] .= count($in['versions']) > 1 ? ' The user took version ' . ($version + 1) . '.' : '';
    return $result;
}

/** The card "Bildtexte": the texts of an image, while they still hold what the card showed before. */
function blog_assistant_apply_file(array $in): array
{
    $file = blog_assistant_file($in['file']) ?? throw new RuntimeException('Datei nicht gefunden');
    foreach ($in['changes'] as $name => $change) {
        if (blog_assistant_current($file, $name) !== $change['before']) {
            throw new BlogAssistantStale('Die Bildtexte wurden seit dem Vorschlag bearbeitet. Nichts geändert.', 'the image texts changed since the proposal');
        }
    }
    return blog_assistant_result(blog_assistant_write($file, array_map(fn ($change) => $change['after'], $in['changes'])));
}

/** Copies a route file into content/.assistant/routes/ before it is replaced: state, in every backup. */
function blog_assistant_keep_route(Page $page, File $file): void
{
    F::copy($file->root(), blog_assistant_log_dir() . '/routes/' . str_replace('/', '+', $page->id()) . '-' . date('Ymd-His') . '-' . $file->filename());
}

/**
 * Writes a line as route file of a page. A draft's old file gets the new
 * content in place. Otherwise the file is new: route.json, or with the time
 * in its name when that exists, because the live page keeps its file until
 * Sven saves.
 */
function blog_assistant_save_route(Page $page, array $line, ?File $old): File
{
    $tmp = blog_uploads_dir() . '/r' . bin2hex(random_bytes(8)) . '.json';
    F::write($tmp, json_encode(['type' => 'LineString', 'coordinates' => $line], JSON_THROW_ON_ERROR));
    try {
        if ($old !== null && $page->isDraft()) {
            return $old->replace($tmp);
        }
        return $page->createFile([
            'source' => $tmp,
            'filename' => $page->file('route.json') === null ? 'route.json' : 'route-' . date('Ymd-His') . '.json',
            'template' => 'route',
        ]);
    } finally {
        F::remove($tmp);
    }
}

/** The fields route, mappoints and mapzoom of a route, as the Panel stores them. */
function blog_assistant_route_fields(File $file, array $route): array
{
    return [
        'route' => Yaml::encode([$file->uuid()->toString()]),
        'mappoints' => Yaml::encode(array_map(fn ($m) => ['label' => $m['label'], 'lat' => blog_route_num($m['lat']), 'lon' => blog_route_num($m['lon'])], $route['markers'])),
        'mapzoom' => $route['zoom'] ? 'true' : 'false',
    ];
}

/** The card "Route": a new route file, points and zoom; the old file is kept as state first. */
function blog_assistant_apply_route(array $in): array
{
    $page = blog_assistant_find($in['page']) ?? throw new RuntimeException('Seite nicht gefunden');
    $now = blog_assistant_route_summary($page);
    if ($now['hash'] !== $in['hash']) {
        throw new BlogAssistantStale('Die Route wurde seit dem Vorschlag geändert. Nichts geändert.', 'the route changed since the proposal');
    }
    $upload = $in['route']['upload'];
    if ($upload !== null && blog_uploads_meta($upload) === null) {
        throw new BlogAssistantStale('Die GPX-Datei dieses Vorschlags ist nicht mehr da. Nichts geändert.', 'the GPX upload is gone');
    }
    $old = $now['file'] !== null ? $page->file($now['file']) : null;
    if ($old !== null) {
        blog_assistant_keep_route($page, $old);
    }
    $file = blog_assistant_save_route($page, $in['route']['line'], $old);
    $result = blog_assistant_result(blog_assistant_write($page, blog_assistant_route_fields($file, $in['route'])));
    if ($upload !== null) {
        blog_uploads_delete($upload);
    }
    return $result;
}

/**
 * A new draft with everything in one click, all or nothing: a step that
 * fails takes the draft away again. The uploads go only after success,
 * so "Nochmal versuchen" works.
 */
function blog_assistant_apply_post(array $in): array
{
    $section = site()->find(BLOG_ASSISTANT_TYPES[$in['type']]['section']) ?? throw new RuntimeException('Bereich nicht gefunden');
    if (($taken = blog_assistant_slug_taken($in['slug'])) !== null) {
        throw new BlogAssistantStale('Diesen URL-Anhang gibt es inzwischen. Nichts geändert.', "the slug is taken by $taken now");
    }
    $sources = [];
    foreach ($in['photos'] as $photo) {
        $sources[$photo['filename']] = blog_uploads_meta($photo['upload'])['path'] ?? throw new BlogAssistantStale('Ein Foto dieses Vorschlags ist nicht mehr da. Nichts geändert.', 'an upload of the card is gone');
    }
    $upload = $in['route']['upload'] ?? null;
    if ($upload !== null && blog_uploads_meta($upload) === null) {
        throw new BlogAssistantStale('Die GPX-Datei dieses Vorschlags ist nicht mehr da. Nichts geändert.', 'the GPX upload is gone');
    }
    $draft = $section->createChild(['slug' => $in['slug'], 'template' => $in['type'], 'content' => $in['content']]);
    try {
        $fields = [];
        foreach ($in['photos'] as $photo) {
            $file = $draft->createFile([
                'source' => $sources[$photo['filename']],
                'filename' => $photo['filename'],
                'template' => 'image',
                // The camera data read on arrival; the stored file has no EXIF any more.
                'content' => array_filter(['alt' => $photo['alt'], 'caption' => $photo['caption'], 'photographer' => $photo['photographer'], ...$photo['exif']], fn ($v) => $v !== ''),
            ]);
            if ($photo['filename'] === $in['cover']) {
                $fields['cover'] = Yaml::encode([$file->uuid()->toString()]);
            }
        }
        if ($in['route'] !== null) {
            $fields += blog_assistant_route_fields(blog_assistant_save_route($draft, $in['route']['line'], null), $in['route']);
        }
        if ($fields !== []) {
            $draft = $draft->update($fields);
        }
    } catch (Throwable $e) {
        $draft->delete(true);
        throw $e;
    }
    foreach ([...array_column($in['photos'], 'upload'), ...array_filter([$upload])] as $id) {
        blog_uploads_delete($id);
    }
    return [
        'text' => 'Als Entwurf angelegt.',
        'link' => $draft->panel()->url(true),
        'linkText' => 'Entwurf öffnen',
        'claude' => ' New draft: ' . $draft->id() . ' (' . $draft->uuid()->toString() . ').',
    ];
}
