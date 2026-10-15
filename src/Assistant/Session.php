<?php

namespace Website\Assistant;

use Closure;

/**
 * Stores the chat in the Kirby session of the Panel user. The chat stays
 * after a reload of the page and ends with the logout. bin/backup.sh does not
 * include sessions, so no backup holds a chat.
 *
 * The decisions on the cards and the notes for Claude have their own session
 * keys. A card is a change that Claude proposed; the chat shows it with
 * buttons to apply or discard it. A round (one request to Claude) writes the
 * whole chat back when it ends. The decisions and notes are outside the
 * chat, so the round cannot overwrite a click made while it ran. The uploads
 * have their own key too (`Uploads::SESSION`).
 */
final class Session
{
    public const CHAT = 'blog.assistant';

    /** The decision on each card: card id => [state, result, warn]. */
    public const DECISIONS = self::CHAT . '.decisions';

    /** Lines for Claude that go with the next message of the Panel user. */
    public const NOTES = self::CHAT . '.notes';

    /**
     * Locks the session and reads it again. The same browser can send a
     * second request while this one runs: "Abbrechen" during a round, a click
     * on a card, an upload. Without the lock, this request would write back
     * its old copy and undo the change of the other request. The lock lasts
     * until the session is written: at the end of the request, or before a
     * request to Claude.
     */
    public static function lock(): void
    {
        kirby()->session()->prepareForWriting();
    }

    /** Writes the session and releases the lock; Kirby's `commit()` does both. */
    public static function unlock(): void
    {
        kirby()->session()->commit();
    }

    public static function load(): ChatState
    {
        $chat = kirby()->session()->get(self::CHAT);
        return is_array($chat) ? ChatState::fromArray($chat) : new ChatState();
    }

    /**
     * Changes one session key under the lock: `$read` reads the current
     * value, and the session stores what `$change` returns. Every write of the
     * assistant goes through this method or `change()`. A value read before
     * the lock can be out of date, and storing a change of it would undo a
     * parallel request.
     */
    public static function update(string $key, Closure $read, Closure $change): mixed
    {
        self::lock();
        $value = $change($read());
        kirby()->session()->set($key, $value);
        return $value;
    }

    /**
     * Changes the chat in the same way as `update()`: `$change` gets the chat
     * as stored now and returns the chat to store.
     */
    public static function change(Closure $change): ChatState
    {
        self::lock();
        $chat = $change(self::load());
        kirby()->session()->set(self::CHAT, $chat->toArray());
        return $chat;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function decisions(): array
    {
        return kirby()->session()->get(self::DECISIONS) ?? [];
    }

    /**
     * @return list<string>
     */
    public static function notes(): array
    {
        return kirby()->session()->get(self::NOTES) ?? [];
    }

    /** Adds a line for Claude to the next message, such as a decision on a card. */
    public static function note(string $note): void
    {
        self::update(self::NOTES, self::notes(...), fn(array $notes) => [...$notes, $note]);
    }

    /**
     * Writes the log line for the end of an answer. With $outcome null the
     * answer goes on with another round, and the line waits. A stop
     * ("Abbrechen") or a new chat ("Neuer Chat") during the round writes
     * that line itself as "stopped", from the chat in the session. Both
     * raise `ChatState::$run`, and this method then writes nothing. In the
     * first round of an answer ($first) the session does not hold the
     * answer yet, because `Chat::send()` stores the chat only after the
     * round. Such a stop writes no line, so this method writes it as
     * "stopped", with the tokens of the round. The lock comes first:
     * otherwise a stop between the check and the log line would log the
     * answer twice or not at all.
     */
    public static function finish(ChatState $chat, ?string $outcome, bool $first = false): ChatState
    {
        self::lock();
        if (self::load()->run !== $chat->run) {
            if ($first) {
                Log::end($chat, 'stopped');
            }
        } elseif ($outcome !== null) {
            Log::end($chat, $outcome);
        }
        return $chat;
    }
}
