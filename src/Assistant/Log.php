<?php

namespace Website\Assistant;

use Throwable;
use Website\Blog\Jsonl;

/**
 * Protokoll of the assistant: one line per answer and per decision on a
 * card, without any content. It records who asked, when, how long the
 * answer took, how many rounds, which tools ran, the model, the tokens and
 * how it ended. Lives in content/.assistant/<year>-<month>.jsonl: state,
 * like the change log, so backups include it and the deploy never deletes it.
 */
final class Log
{
    public static function dir(): string
    {
        return kirby()->root('content') . '/.assistant';
    }

    /**
     * Appends one entry (Website\Blog\Jsonl).
     *
     * @param array<string, mixed> $entry
     */
    public static function write(array $entry): void
    {
        try {
            Jsonl::append(self::dir(), $entry);
        } catch (Throwable $e) {
            // A log line must never break the chat.
            error_log('blog assistant log: ' . $e->getMessage());
        }
    }

    /**
     * The newest entries, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function read(int $limit = 300): array
    {
        return Jsonl::read(self::dir(), $limit);
    }

    /** Starts the measurement of one answer. */
    public static function start(ChatState $chat): void
    {
        $chat->id ??= bin2hex(random_bytes(4));
        $chat->asked++;
        $chat->turn = ['start' => microtime(true), 'rounds' => 0, 'tools' => [], 'cards' => 0, 'in' => 0, 'cached' => 0, 'out' => 0];
    }

    /**
     * Counts one round: its tools and tokens.
     *
     * @param array<string, mixed> $response
     */
    public static function round(ChatState $chat, array $response): void
    {
        if ($chat->turn === null) {
            return;
        }
        $chat->turn['rounds']++;
        foreach ($response['content'] ?? [] as $block) {
            if (in_array($block['type'] ?? '', ['tool_use', 'server_tool_use'], true)) {
                $name = (string) ($block['name'] ?? '?');
                $chat->turn['tools'][$name] = ($chat->turn['tools'][$name] ?? 0) + 1;
                $chat->turn['cards'] += str_starts_with($name, 'propose_') ? 1 : 0;
            }
        }
        $usage = $response['usage'] ?? [];
        $chat->turn['in'] += (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['cache_read_input_tokens'] ?? 0) + (int) ($usage['cache_creation_input_tokens'] ?? 0);
        $chat->turn['out'] += (int) ($usage['output_tokens'] ?? 0);
        // Read from the prompt cache: the fixed rules, the tools and the chat so far.
        $chat->turn['cached'] += (int) ($usage['cache_read_input_tokens'] ?? 0);
    }

    /** Ends the measurement and writes the line: ok, stopped or the error kind. */
    public static function end(ChatState $chat, string $outcome): void
    {
        if ($chat->turn === null) {
            return;
        }
        $turn = $chat->turn;
        self::write([
            'type' => 'answer',
            'chat' => $chat->id,
            'message' => $chat->asked,
            'model' => $chat->model,
            'seconds' => round(microtime(true) - $turn['start'], 1),
            'rounds' => $turn['rounds'],
            'tools' => $turn['tools'],
            'cards' => $turn['cards'],
            'tokensIn' => $turn['in'],
            'tokensOut' => $turn['out'],
            'tokensCached' => $turn['cached'] ?? 0,
            'outcome' => $outcome,
        ]);
        $chat->turn = null;
    }

    /**
     * What the Protokoll page shows: the rows and the totals.
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int|string>}
     */
    public static function view(): array
    {
        $entries = self::read();
        $answers = array_values(array_filter($entries, fn($e) => ($e['type'] ?? '') === 'answer'));
        $decisions = array_values(array_filter($entries, fn($e) => ($e['type'] ?? '') === 'decision'));
        $seconds = array_column($answers, 'seconds');
        $rows = array_map(fn($e) => [
            'time' => date('d.m.Y H:i', strtotime($e['time'])),
            'name' => $e['name'] ?? $e['user'] ?? '',
            'chat' => ($e['chat'] ?? '?') . ' · ' . ($e['message'] ?? '?'),
            'seconds' => number_format((float) ($e['seconds'] ?? 0), 1, ',', '') . ' s',
            'rounds' => (int) ($e['rounds'] ?? 0),
            'tools' => implode(', ', array_map(fn($name, $n) => $n > 1 ? "$name × $n" : $name, array_keys($e['tools'] ?? []), $e['tools'] ?? [])),
            'outcome' => match ($e['outcome'] ?? '') {
                'ok' => 'Fertig',
                'stopped' => 'Abgebrochen',
                default => 'Fehler: ' . ($e['outcome'] ?? '?'),
            },
            'model' => Claude::MODELS[$e['model'] ?? ''] ?? (string) ($e['model'] ?? ''),
            'tokens' => number_format((int) ($e['tokensIn'] ?? 0), 0, ',', '.') . ' / ' . number_format((int) ($e['tokensOut'] ?? 0), 0, ',', '.'),
            'cached' => ($e['tokensIn'] ?? 0) > 0 ? round(100 * ($e['tokensCached'] ?? 0) / $e['tokensIn']) . ' %' : '',
        ], $answers);
        return [
            'rows' => $rows,
            'totals' => [
                'chats' => count(array_unique(array_filter(array_column($answers, 'chat')))),
                'answers' => count($answers),
                'seconds' => $seconds === [] ? '0' : number_format(array_sum($seconds) / count($seconds), 1, ',', ''),
                'accepted' => count(array_filter($decisions, fn($d) => ($d['decision'] ?? '') === 'done')),
                'discarded' => count(array_filter($decisions, fn($d) => ($d['decision'] ?? '') === 'discarded')),
            ],
        ];
    }
}
