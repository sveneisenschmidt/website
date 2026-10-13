<?php

namespace Website\Assistant;

/**
 * The state of one chat with Claude: what goes to the API, what the Panel
 * shows, and the counters. A round is one request to Claude; one message of
 * the Panel user can take several. The session stores the chat as the plain
 * array of `toArray()` under the key `Session::CHAT`. A chat stored by an
 * older version of this class can lack a key; `fromArray()` then uses the
 * default.
 */
final class ChatState
{
    /**
     * Set by the first message and kept for the whole chat. The history holds
     * Claude's thinking blocks, and the API drops them for any other model.
     */
    public ?string $model = null;

    /**
     * The system text, set by the first message and kept for the whole chat.
     * The prompt cache of the API matches requests byte by byte, so every
     * round sends the same bytes. A change of the prompt under Website ›
     * Assistent reaches new chats only.
     *
     * @var ?list<array<string, mixed>>
     */
    public ?array $system = null;

    /**
     * The tool definitions, kept for the whole chat like `$system` and for the
     * same reason.
     *
     * @var ?list<array<string, mixed>>
     */
    public ?array $tools = null;

    /**
     * The day of the last message in Europe/Berlin. A message on a later day
     * tells Claude the new date (`Chat::send()`).
     */
    public ?string $date = null;

    /**
     * The history in the format of the Messages API. Claude's answers go back
     * unchanged, thinking blocks included: the API invalidates a thinking
     * block when the history before it changes.
     *
     * @var list<array<string, mixed>>
     */
    public array $messages = [];

    /**
     * What the Panel shows, in order. The `type` of an item is one of me
     * (a message of the Panel user), ai, card, choice, progress, sources,
     * note and error.
     *
     * @var list<array<string, mixed>>
     */
    public array $items = [];

    /**
     * The cards by id, as `Proposals` built them. A card is a change that
     * Claude proposed; the chat shows it with buttons to apply or discard it.
     * The decisions on the cards are in their own session key
     * (`Session::DECISIONS`).
     *
     * @var array<string, array<string, mixed>>
     */
    public array $proposals = [];

    /**
     * Rounds since the last message of the Panel user. A round is one request
     * to Claude; `Chat::MAX_ROUNDS` limits the count.
     */
    public int $rounds = 0;

    /** True while Claude's last answer called tools: the browser then requests the next round. */
    public bool $next = false;

    /** True when the API paused a long web search (stop reason `pause_turn`). */
    public bool $paused = false;

    /**
     * Rises by one with each stop and each new chat. When it changed during a
     * round, `Chat::keep()` drops the answer of that round.
     */
    public int $run = 0;

    /** The number of images in the chat, at most `Chat::IMAGES`. */
    public int $images = 0;

    /**
     * URLs from the web search results of this chat. They are the only links
     * that the chat view makes clickable.
     *
     * @var array<string, true>
     */
    public array $sources = [];

    /** A random id of the chat for the usage log (`Log`), which the Panel page "Protokoll" shows. */
    public ?string $id = null;

    /** For the usage log: the number of messages the Panel user sent in this chat. */
    public int $asked = 0;

    /**
     * For the usage log: duration, rounds, tools and tokens of the answer in
     * progress (`Log::start()`), or null.
     *
     * @var ?array<string, mixed>
     */
    public ?array $turn = null;

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $chat = new self();
        $chat->model = $data['model'] ?? null;
        $chat->system = $data['system'] ?? null;
        $chat->tools = $data['tools'] ?? null;
        $chat->date = $data['date'] ?? null;
        $chat->messages = $data['messages'] ?? [];
        $chat->items = $data['items'] ?? [];
        $chat->proposals = $data['proposals'] ?? [];
        $chat->rounds = $data['rounds'] ?? 0;
        $chat->next = $data['next'] ?? false;
        $chat->paused = $data['paused'] ?? false;
        $chat->run = $data['run'] ?? 0;
        $chat->images = $data['images'] ?? 0;
        $chat->sources = $data['sources'] ?? [];
        $chat->id = $data['id'] ?? null;
        $chat->asked = $data['asked'] ?? 0;
        $chat->turn = $data['turn'] ?? null;
        return $chat;
    }

    /**
     * Every property, in the order of the declarations. A new property also
     * needs a line in `fromArray()`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
