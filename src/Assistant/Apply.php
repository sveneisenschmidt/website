<?php

namespace Website\Assistant;

use Closure;
use Kirby\Cms\File;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use Kirby\Data\Yaml;
use Kirby\Filesystem\F;
use Throwable;
use Website\Blog\Lock;
use Website\Blog\Redirects;
use Website\Blog\Text;
use Website\Blog\Types;
use Website\Models\PostPage;

/**
 * Applies or discards a card when the Panel user clicks it. A card is a
 * change that Claude proposed; the chat shows it with buttons to apply or
 * discard it. This is the only class of the assistant that changes content.
 * It runs as the logged-in account, so "Verlauf" (the change log in the
 * Panel) lists the change like an edit by hand. A draft gets the change at
 * once. A published post and About get it as unsaved changes: it goes live
 * only when the Panel user clicks "Speichern", and it can be discarded there.
 * A new slug of a published post goes live at once, because Kirby keeps no
 * unsaved slug.
 * Claude learns the decision with the next message.
 */
final class Apply
{
    /** Applies or discards a card. $version picks one of the versions of a card "Passage". */
    public static function decide(string $id, string $action, ?int $version = null): ChatState
    {
        if (!in_array($action, ['apply', 'discard'], true)) {
            throw new Error('chat', 'Unbekannte Entscheidung');
        }
        // The lock makes a second click on the same card wait for the first.
        // It then sees the first decision and changes nothing.
        return self::exclusive(fn() => self::decideLocked($id, $action, $version));
    }

    /**
     * Runs $action under the lock of the cards: decide() and `Chat::reset()`
     * run one at a time, so a new chat does not delete the uploads that a
     * new draft still copies. It is a lock file of its own, not the lock of
     * the session: a new draft renders the thumbnails of its photos for up
     * to 20 s (`Website\Blog\Images`), and every other request of the same
     * browser would wait that long for the session. A request releases the
     * session before it waits here, because the request that holds this
     * lock needs the session to store its decision.
     *
     * @template T
     * @param Closure(): T $action
     * @return T
     */
    public static function exclusive(Closure $action): mixed
    {
        Session::unlock();
        return Lock::run(Uploads::root() . '/.decide.lock', LOCK_EX, $action);
    }

    private static function decideLocked(string $id, string $action, ?int $version): ChatState
    {
        // The lock of the session reads the session again, so the check below
        // sees a decision that another request has just stored. The session
        // is free again before the change runs.
        Session::lock();
        $chat = Session::load();
        $proposal = $chat->proposals[$id] ?? throw new Error('chat', 'Vorschlag nicht gefunden');
        // Decisions have their own session key, because a request to Claude
        // that runs meanwhile writes the whole chat back but never this key.
        $decisions = Session::decisions();
        Session::unlock();
        // A card whose change failed can be tried again; any other decision is final.
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
     * Runs the change of a card. Returns the text and the link that the card
     * shows afterwards, and the note for Claude.
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
            'slug' => self::slug($in),
            default => throw new Error('chat', 'Unbekannter Vorschlag'),
        };
    }

    /**
     * Writes fields. A draft and its files get them at once. A published
     * post, About and their files get them as unsaved changes (Kirby's
     * version `changes`), which go live only with "Speichern".
     *
     * @param array<string, mixed> $fields
     */
    private static function write(ModelWithContent $model, array $fields): ModelWithContent
    {
        $changes = $model->version('changes');
        if (Content::isDraft($model)) {
            $model = $model->update($fields);
            // A draft can have unsaved changes in the Panel as well. They get
            // the new values too, or the next "Speichern" would write the old
            // values back. Unsaved changes equal to the saved content are deleted.
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
     * Applies a card "Feld" (a new value for a whole field). It refuses when
     * the field changed since the proposal.
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
     * Applies a card "Passage" with the version that the Panel user picked.
     * It refuses unless the passage still occurs exactly once in the field.
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
        $after = str_replace($in['passage'], $text, $current);
        // The text around the passage can have changed since the proposal, and
        // with it the raw HTML that the version makes there (Proposals::edit()).
        if (Proposals::addsHtml($current, $after)) {
            throw new Stale('Mit dieser Fassung bekäme das Feld rohes HTML. Nichts geändert.', 'the field would get raw HTML');
        }
        $result = self::result(self::write($page, [$in['field'] => $after]));
        $result['claude'] .= count($in['versions']) > 1 ? ' The user took version ' . ($version + 1) . '.' : '';
        return $result;
    }

    /**
     * Applies a card "Bildtexte" (alt text, caption and photographer of an
     * image). It refuses when one of these texts changed since the proposal.
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

    /**
     * Copies a route file into content/.assistant/routes/ before a new route
     * replaces it. The copy is under content/, so bin/backup.sh includes it
     * and the old route can be restored.
     */
    private static function keepRoute(Page $page, File $file): void
    {
        F::copy($file->root(), Log::dir() . '/routes/' . str_replace('/', '+', $page->id()) . '-' . date('Ymd-His') . '-' . $file->filename());
    }

    /**
     * Writes a line as the route file of a page. The old file of a draft gets
     * the new content in place. A published page gets a new file: route.json,
     * or route-<time>.json when route.json exists. The live page keeps its
     * old file until the Panel user saves, because only the unsaved changes
     * point to the new one.
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
     * Applies a card "Route": a new route file, map points and zoom. It
     * refuses when the route changed since the proposal. The old file is
     * copied first (`keepRoute()`).
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
     * Applies a card "URL": a new slug. Kirby moves the folder of the post at
     * once, so a published post gets its new URL with the click. The hook
     * `page.changeSlug:after` (plugins/blog) adds the redirect from the old
     * URL of a published post, also for a change in the Panel. A card
     * without redirect removes it again. It refuses when the slug or the
     * status changed since the proposal, because the card text depends on
     * both, or when another page has the new slug now.
     *
     * @param array<string, mixed> $in
     * @return array<string, string>
     */
    private static function slug(array $in): array
    {
        $page = Content::find($in['page']);
        if (!$page instanceof PostPage) {
            throw new Error('chat', 'Seite nicht gefunden');
        }
        if ($page->slug() !== $in['before']) {
            throw new Stale('Die URL wurde seit dem Vorschlag geändert. Nichts geändert.', 'the slug changed since the proposal');
        }
        $draft = $page->isDraft();
        if ($draft !== $in['draft']) {
            throw new Stale('Der Beitrag wurde seit dem Vorschlag veröffentlicht oder zurückgezogen. Nichts geändert.', 'the status of the post changed since the proposal');
        }
        if (($taken = Content::slugTaken($in['slug'])) !== null) {
            throw new Stale('Diesen URL-Anhang gibt es inzwischen. Nichts geändert.', "the slug is taken by $taken now");
        }
        $from = $page->postPath();
        $page = $page->changeSlug($in['slug']);
        if (!$draft && !$in['redirect']) {
            Redirects::remove($from);
        }
        return [
            'text' => match (true) {
                $draft => 'URL des Entwurfs geändert.',
                $in['redirect'] => 'URL geändert. Die alte URL leitet weiter.',
                default => 'URL geändert. Die alte URL leitet nicht weiter.',
            },
            'link' => $page->panel()->url(true),
            'linkText' => 'Öffnen',
            'claude' => ' New id: ' . $page->id() . '.' . match (true) {
                $draft => '',
                $in['redirect'] => ' The old URL redirects to the new one.',
                default => ' The old URL has no redirect.',
            },
        ];
    }

    /**
     * Applies a card "Neuer Entwurf": creates the draft with its photos and
     * route in one click. When a step fails, the draft is deleted again, so
     * the click creates all or nothing. The uploads are deleted only after
     * success, so the button "Nochmal versuchen" still finds them.
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
                    // The camera data comes from the metadata of the upload,
                    // read on arrival. The stored file has no EXIF (`Strip`).
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

    /** Refuses a card whose GPX upload is gone, for example after 7 days (`Uploads::cleanup()`). */
    private static function gpxPresent(?string $upload): void
    {
        if ($upload !== null && Uploads::meta($upload) === null) {
            throw new Stale('Die GPX-Datei dieses Vorschlags ist nicht mehr da. Nichts geändert.', 'the GPX upload is gone');
        }
    }
}
