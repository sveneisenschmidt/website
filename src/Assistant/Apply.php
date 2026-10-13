<?php

namespace Website\Assistant;

use Kirby\Cms\File;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use Kirby\Data\Yaml;
use Kirby\Filesystem\F;
use Throwable;
use Website\Blog\Text;
use Website\Blog\Types;

/**
 * Sven's click on a card. Only here does anything change, and always as
 * his account, so "Verlauf" logs it like his own edit. A draft gets the change
 * at once. A published post and About get
 * it as Kirby's unsaved changes: live only with his "Speichern" in the
 * Panel, and he can discard it there. The next message tells Claude.
 */
final class Apply
{
    /** Applies or discards a proposal; $version picks one of several versions. Returns the chat. */
    public static function decide(string $id, string $action, ?int $version = null): ChatState
    {
        if (!in_array($action, ['apply', 'discard'], true)) {
            throw new Error('chat', 'Unbekannte Entscheidung');
        }
        // A second click on the same card waits for the first and then sees its decision.
        Session::lock();
        $chat = Session::load();
        $proposal = $chat->proposals[$id] ?? throw new Error('chat', 'Vorschlag nicht gefunden');
        // Decisions live in their own session key: a round that runs meanwhile
        // stores the chat, never the decisions.
        $decisions = Session::decisions();
        // A failed change may be tried again; a decided one stays.
        if (!in_array($decisions[$id]['state'] ?? 'open', ['open', 'failed'], true)) {
            return Session::load();
        }
        $card = 'Card "' . $proposal['title'] . '"';
        if ($action === 'discard') {
            $decision = ['state' => 'discarded'];
            Session::note("($card: discarded by the user.)");
        } else {
            try {
                $result = self::apply($proposal, $version);
                $decision = ['state' => 'done', 'result' => $result];
                Session::note("($card: applied by the user." . $result['claude'] . ')');
            } catch (Stale $e) {
                $decision = ['state' => 'stale', 'warn' => $e->getMessage()];
                Session::note("($card: not applied, {$e->reason}. Nothing changed.)");
            } catch (Throwable $e) {
                $decision = ['state' => 'failed'];
                error_log('blog assistant: ' . $e::class . ': ' . $e->getMessage());
                Session::note("($card: failed, nothing changed. The user may try again.)");
            }
        }
        Session::update(Session::DECISIONS, Session::decisions(...), function (array $decisions) use ($id, $decision): array {
            $decisions[$id] = $decision;
            return $decisions;
        });
        Log::write(['type' => 'decision', 'chat' => $chat->id, 'kind' => $proposal['kind'], 'decision' => $decision['state']]);
        return Session::load();
    }

    /**
     * Runs the change of a card. Returns what the card shows after it, and the note for Claude.
     *
     * @param array<string, mixed> $proposal
     * @return array<string, string>
     */
    private static function apply(array $proposal, ?int $version): array
    {
        $in = $proposal['input'];
        return match ($proposal['kind']) {
            'post' => self::post($in),
            'field' => self::field($in),
            'edit' => self::edit($in, $version ?? 0),
            'file' => self::file($in),
            'route' => self::route($in),
            default => throw new Error('chat', 'Unbekannter Vorschlag'),
        };
    }

    /**
     * Writes fields: into a draft at once, into a published
     * post, About and their files only as Kirby's unsaved changes.
     *
     * @param array<string, mixed> $fields
     */
    private static function write(ModelWithContent $model, array $fields): ModelWithContent
    {
        $changes = $model->version('changes');
        if (Content::isDraft($model)) {
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

    /**
     * What a card shows after the click, and the note for Claude.
     *
     * @return array<string, string>
     */
    private static function result(ModelWithContent $model): array
    {
        $draft = Content::isDraft($model);
        return [
            'text' => $draft ? 'Im Entwurf gespeichert.' : 'In den Änderungen gespeichert. Live erst mit deinem Speichern.',
            'link' => $model->panel()->url(true),
            'linkText' => $model instanceof File ? 'Datei öffnen' : 'Öffnen',
            'claude' => $draft ? '' : ' It waits in the unsaved changes until the user saves in the Panel.',
        ];
    }

    /**
     * The card "Feld": the whole field, while it still holds what the card showed before.
     *
     * @param array<string, mixed> $in
     * @return array<string, string>
     */
    private static function field(array $in): array
    {
        $page = Content::find($in['page']) ?? throw new Error('chat', 'Seite nicht gefunden');
        if (Content::current($page, $in['field']) !== $in['before']) {
            throw new Stale('Das Feld wurde seit dem Vorschlag bearbeitet. Nichts geändert.', 'the field changed since the proposal');
        }
        return self::result(self::write($page, [$in['field'] => $in['value']]));
    }

    /**
     * The card "Passage": the version Sven picked, while the passage still occurs exactly once.
     *
     * @param array<string, mixed> $in
     * @return array<string, string>
     */
    private static function edit(array $in, int $version): array
    {
        $page = Content::find($in['page']) ?? throw new Error('chat', 'Seite nicht gefunden');
        $text = $in['versions'][$version] ?? throw new Error('chat', 'Fassung nicht gefunden');
        $current = Content::current($page, $in['field']);
        if (substr_count($current, $in['passage']) !== 1) {
            throw new Stale('Die Passage steht nicht mehr genau einmal im Feld. Nichts geändert.', 'the passage no longer occurs exactly once');
        }
        $result = self::result(self::write($page, [$in['field'] => str_replace($in['passage'], $text, $current)]));
        $result['claude'] .= count($in['versions']) > 1 ? ' The user took version ' . ($version + 1) . '.' : '';
        return $result;
    }

    /**
     * The card "Bildtexte": the texts of an image, while they still hold what the card showed before.
     *
     * @param array<string, mixed> $in
     * @return array<string, string>
     */
    private static function file(array $in): array
    {
        $file = Content::file($in['file']) ?? throw new Error('chat', 'Datei nicht gefunden');
        foreach ($in['changes'] as $name => $change) {
            if (Content::current($file, $name) !== $change['before']) {
                throw new Stale('Die Bildtexte wurden seit dem Vorschlag bearbeitet. Nichts geändert.', 'the image texts changed since the proposal');
            }
        }
        return self::result(self::write($file, array_map(fn($change) => $change['after'], $in['changes'])));
    }

    /** Copies a route file into content/.assistant/routes/ before it is replaced: state, in every backup. */
    private static function keepRoute(Page $page, File $file): void
    {
        F::copy($file->root(), Log::dir() . '/routes/' . str_replace('/', '+', $page->id()) . '-' . date('Ymd-His') . '-' . $file->filename());
    }

    /**
     * Writes a line as route file of a page. A draft's old file gets the new
     * content in place. Otherwise the file is new: route.json, or with the time
     * in its name when that exists, because the live page keeps its file until
     * Sven saves.
     *
     * @param list<array<int, float|int>> $line
     */
    private static function saveRoute(Page $page, array $line, ?File $old): File
    {
        $tmp = Uploads::dir() . '/r' . bin2hex(random_bytes(8)) . '.json';
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

    /**
     * The fields route, mappoints and mapzoom of a route, as the Panel stores them.
     *
     * @param array<string, mixed> $route
     * @return array<string, string>
     */
    private static function routeFields(File $file, array $route): array
    {
        return [
            'route' => Yaml::encode([$file->uuid()->toString()]),
            'mappoints' => Yaml::encode(array_map(fn($m) => ['label' => $m['label'], 'lat' => Text::number($m['lat'], 6), 'lon' => Text::number($m['lon'], 6)], $route['markers'])),
            'mapzoom' => $route['zoom'] ? 'true' : 'false',
        ];
    }

    /**
     * The card "Route": a new route file, points and zoom; the old file is kept as state first.
     *
     * @param array<string, mixed> $in
     * @return array<string, string>
     */
    private static function route(array $in): array
    {
        $page = Content::find($in['page']) ?? throw new Error('chat', 'Seite nicht gefunden');
        $now = Content::routeSummary($page);
        if ($now['hash'] !== $in['hash']) {
            throw new Stale('Die Route wurde seit dem Vorschlag geändert. Nichts geändert.', 'the route changed since the proposal');
        }
        $upload = $in['route']['upload'];
        self::gpxPresent($upload);
        $old = $now['file'] !== null ? $page->file($now['file']) : null;
        if ($old !== null) {
            self::keepRoute($page, $old);
        }
        $file = self::saveRoute($page, $in['route']['line'], $old);
        $result = self::result(self::write($page, self::routeFields($file, $in['route'])));
        if ($upload !== null) {
            Uploads::delete($upload);
        }
        return $result;
    }

    /**
     * A new draft with everything in one click, all or nothing: a step that
     * fails takes the draft away again. The uploads go only after success,
     * so "Nochmal versuchen" works.
     *
     * @param array<string, mixed> $in
     * @return array<string, string>
     */
    private static function post(array $in): array
    {
        $section = site()->find(Types::ALL[$in['type']]['section']) ?? throw new Error('chat', 'Bereich nicht gefunden');
        if (($taken = Content::slugTaken($in['slug'])) !== null) {
            throw new Stale('Diesen URL-Anhang gibt es inzwischen. Nichts geändert.', "the slug is taken by $taken now");
        }
        $sources = [];
        foreach ($in['photos'] as $photo) {
            $sources[$photo['filename']] = Uploads::meta($photo['upload'])['path'] ?? throw new Stale('Ein Foto dieses Vorschlags ist nicht mehr da. Nichts geändert.', 'an upload of the card is gone');
        }
        $upload = $in['route']['upload'] ?? null;
        self::gpxPresent($upload);
        $draft = $section->createChild(['slug' => $in['slug'], 'template' => $in['type'], 'content' => $in['content']]);
        try {
            $fields = [];
            foreach ($in['photos'] as $photo) {
                $file = $draft->createFile([
                    'source' => $sources[$photo['filename']],
                    'filename' => $photo['filename'],
                    'template' => 'image',
                    // The camera data read on arrival; the stored file has no EXIF any more.
                    'content' => array_filter(['alt' => $photo['alt'], 'caption' => $photo['caption'], 'photographer' => $photo['photographer'], ...$photo['exif']], fn($v) => $v !== ''),
                ]);
                if ($photo['filename'] === $in['cover']) {
                    $fields['cover'] = Yaml::encode([$file->uuid()->toString()]);
                }
            }
            if ($in['route'] !== null) {
                $fields += self::routeFields(self::saveRoute($draft, $in['route']['line'], null), $in['route']);
            }
            if ($fields !== []) {
                $draft = $draft->update($fields);
            }
        } catch (Throwable $e) {
            $draft->delete(true);
            throw $e;
        }
        foreach ([...array_column($in['photos'], 'upload'), ...array_filter([$upload])] as $id) {
            Uploads::delete($id);
        }
        return [
            'text' => 'Als Entwurf angelegt.',
            'link' => $draft->panel()->url(true),
            'linkText' => 'Entwurf öffnen',
            'claude' => ' New draft: ' . $draft->id() . ' (' . $draft->uuid()->toString() . ').',
        ];
    }

    /** A card with a GPX upload can only apply while the upload is there. */
    private static function gpxPresent(?string $upload): void
    {
        if ($upload !== null && Uploads::meta($upload) === null) {
            throw new Stale('Die GPX-Datei dieses Vorschlags ist nicht mehr da. Nichts geändert.', 'the GPX upload is gone');
        }
    }
}
