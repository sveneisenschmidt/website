<?php

namespace Website\Assistant;

/**
 * One chat, with every key a chat gets. The session stores it as the plain
 * array of toArray(), in the shape it always had (Session::CHAT): a chat
 * stored before a key came here lacks the key and gets its default.
 */
final class ChatState
{
    /** The model, frozen for the chat: thinking blocks are bound to it. */
    public ?string $model = null;

    /**
     * The system text, frozen for the chat: every round sends the same bytes.
     *
     * @var ?list<array<string, mixed>>
     */
    public ?array $system = null;

    /**
     * The tool list, frozen like the system text: the same bytes keep the prompt cache.
     *
     * @var ?list<array<string, mixed>>
     */
    public ?array $tools = null;

    /** The day of the last message, Europe/Berlin: a new day comes as a note. */
    public ?string $date = null;

    /**
     * The history as Claude gets it, append-only: Claude's answers go back
     * unchanged, thinking blocks included.
     *
     * @var list<array<string, mixed>>
     */
    public array $messages = [];

    /**
     * What the Panel shows: Sven's messages, answers, cards, choices, progress lines.
     *
     * @var list<array<string, mixed>>
     */
    public array $items = [];

    /**
     * The cards by id. Sven's decisions on them live in their own key (Session::DECISIONS).
     *
     * @var array<string, array<string, mixed>>
     */
    public array $proposals = [];

    /** Rounds since Sven's last message (Chat::MAX_ROUNDS). */
    public int $rounds = 0;

    /** True while Claude still calls tools: the browser asks for the next round. */
    public bool $next = false;

    /** True after a long web search paused the turn. */
    public bool $paused = false;

    /** Counts up with each stop and each new chat: the answer of a round running meanwhile is dropped. */
    public int $run = 0;

    /** The images the chat holds (Chat::IMAGES). */
    public int $images = 0;

    /**
     * URLs from Sven's messages: links a card may hold.
     *
     * @var array<string, true>
     */
    public array $mine = [];

    /**
     * URLs from the web search: links a card may hold, and the only links
     * the chat makes clickable.
     *
     * @var array<string, true>
     */
    public array $sources = [];

    /** For the Protokoll (Log): the chat's id. */
    public ?string $id = null;

    /** For the Protokoll: Sven's messages so far. */
    public int $asked = 0;

    /**
     * For the Protokoll: the answer being measured.
     *
     * @var ?array<string, mixed>
     */
    public ?array $turn = null;

    /**
     * A chat as the session stores it.
     *
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
        $chat->mine = $data['mine'] ?? [];
        $chat->sources = $data['sources'] ?? [];
        $chat->id = $data['id'] ?? null;
        $chat->asked = $data['asked'] ?? 0;
        $chat->turn = $data['turn'] ?? null;
        return $chat;
    }

    /**
     * The chat as the session stores it: every property, in the order above.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
