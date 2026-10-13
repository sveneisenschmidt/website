<?php

namespace Website\Assistant;

use Closure;

/**
 * The chat lives in the Kirby session, so it survives a reload and goes
 * with the logout. Generated data, never in a backup. Sven's decisions on
 * the cards and the notes for Claude have keys of their own: a round that
 * runs meanwhile stores the chat, never them. The uploads have theirs too
 * (Uploads::SESSION).
 */
final class Session
{
    public const CHAT = 'blog.assistant';

    /** Sven's decisions on the cards: id => [state, result, warn]. */
    public const DECISIONS = self::CHAT . '.decisions';

    /** The lines that wait for Sven's next message. */
    public const NOTES = self::CHAT . '.notes';

    /**
     * Locks the session and reads it anew. A parallel request of the same
     * browser ("Abbrechen" while a round runs, a click on a card, an upload)
     * may have written it since this request read it; without the lock, this
     * request would write its old copy back. The lock holds until the session
     * is written: at the end of the request, or before a request to Claude.
     */
    public static function lock(): void
    {
        kirby()->session()->prepareForWriting();
    }

    /** Writes the session and frees its lock. */
    public static function unlock(): void
    {
        kirby()->session()->commit();
    }

    /** The chat of this session, or a new one. */
    public static function load(): ChatState
    {
        $chat = kirby()->session()->get(self::CHAT);
        return is_array($chat) ? ChatState::fromArray($chat) : new ChatState();
    }

    /**
     * Changes one key of the session: locks it, reads the key anew with $read
     * and stores what $change returns for it. Every write goes through here or
     * through change(): a change of a value read before the lock would undo
     * what a parallel request wrote meanwhile.
     */
    public static function update(string $key, Closure $read, Closure $change): mixed
    {
        self::lock();
        $value = $change($read());
        kirby()->session()->set($key, $value);
        return $value;
    }

    /**
     * Changes the chat as update() changes a key: $change gets the chat read
     * anew under the lock and returns the chat to store.
     */
    public static function change(Closure $change): ChatState
    {
        self::lock();
        $chat = $change(self::load());
        kirby()->session()->set(self::CHAT, $chat->toArray());
        return $chat;
    }

    /**
     * Sven's decisions on the cards.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function decisions(): array
    {
        return kirby()->session()->get(self::DECISIONS) ?? [];
    }

    /**
     * The lines that wait for Sven's next message.
     *
     * @return list<string>
     */
    public static function notes(): array
    {
        return kirby()->session()->get(self::NOTES) ?? [];
    }

    /** A line Claude gets with Sven's next message, such as a decision on a card. */
    public static function note(string $note): void
    {
        self::update(self::NOTES, self::notes(...), fn(array $notes) => [...$notes, $note]);
    }

    /**
     * Logs the end of an answer, unless a stop or "Neuer Chat" came meanwhile:
     * they log it themselves and count run up. The lock comes first, so a stop
     * cannot come between the check and the log line and log the answer twice.
     */
    public static function finish(ChatState $chat, string $outcome): ChatState
    {
        self::lock();
        if (self::load()->run === $chat->run) {
            Log::end($chat, $outcome);
        }
        return $chat;
    }
}
