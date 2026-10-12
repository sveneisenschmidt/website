<?php

use Kirby\Cms\File;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;

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
