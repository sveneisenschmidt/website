<?php

namespace Website\Assistant;

use Closure;
use Kirby\Http\Remote;
use Throwable;

/**
 * One request to the Claude Messages API. Raw HTTP through Kirby's Remote,
 * as on concertshotsbyluisa.de: the deploy installs no SDK.
 * The key lives in env.php ('assistantKey'), set by the deploy.
 * Tests swap the transport with the option 'blog.assistant.transport':
 * a closure that takes the request body and returns [status, body].
 */
final class Claude
{
    public const API = 'https://api.anthropic.com/v1/messages';

    public const MODELS = [
        'claude-opus-5-5' => 'Claude Opus 5.5',
        'claude-sonnet-5-5' => 'Claude Sonnet 5.5',
        'claude-haiku-5-5' => 'Claude Haiku 5.5',
    ];

    public const DEFAULT_MODEL = 'claude-opus-5-5';

    /**
     * Opus and Sonnet: the server-side fallback and the web search with
     * dynamic filtering. Haiku has neither.
     */
    public static function fullModel(string $model): bool
    {
        return !str_contains($model, 'haiku');
    }

    public static function key(): ?string
    {
        $key = trim((string) option('assistantKey', ''));
        return $key === '' ? null : $key;
    }

    /** The model from Website › Assistent, or the default when the saved one is gone. */
    public static function model(): string
    {
        $model = (string) site()->content()->get('assistantModel');
        return array_key_exists($model, self::MODELS) ? $model : self::DEFAULT_MODEL;
    }

    /**
     * Sends one request body, returns the decoded response.
     * Throws Error with the kind the Panel needs.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function request(array $body): array
    {
        $transport = option('blog.assistant.transport');
        if ($transport instanceof Closure) {
            [$status, $response] = $transport($body);
        } else {
            $key = self::key() ?? throw new Error('access');
            $headers = [
                'x-api-key: ' . $key,
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
            ];
            // A refused request falls back server-side.
            if (self::fullModel($body['model'])) {
                $headers[] = 'anthropic-beta: server-side-fallback-2026-07-01';
                $body['fallbacks'] = 'default';
            }
            // One round can take longer than PHP's default 30 s (Opus, a web search).
            // set_time_limit may be disabled on a host; then its limit stays.
            @set_time_limit(180);
            try {
                $remote = Remote::request(self::API, [
                    'method' => 'POST',
                    'headers' => $headers,
                    'data' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'timeout' => 150,
                ]);
            } catch (Throwable $e) {
                error_log('blog assistant: Anthropic ' . $e::class . ': ' . $e->getMessage());
                throw new Error('busy');
            }
            $status = $remote->code();
            $response = json_decode((string) $remote->content(), true) ?? [];
        }

        if ($status === 200) {
            if (($response['stop_reason'] ?? null) === 'refusal') {
                throw new Error('refusal');
            }
            // An answer without a list of blocks is no answer: nothing goes into the chat.
            $content = $response['content'] ?? null;
            if (!is_array($content) || $content === [] || !array_is_list($content) || array_filter($content, fn($block) => !is_string($block['type'] ?? null)) !== []) {
                error_log('blog assistant: Anthropic 200 without content');
                throw new Error('busy');
            }
            return $response;
        }
        $message = (string) ($response['error']['message'] ?? '');
        error_log('blog assistant: Anthropic ' . $status . ' ' . $message);
        throw new Error(match (true) {
            $status === 401, $status === 403 => 'access',
            $status === 402, $status === 400 && stripos($message, 'credit') !== false => 'credit',
            // A request that will never work: invalid history, retired model, chat too big.
            in_array($status, [400, 404, 413], true) => 'chat',
            default => 'busy',
        });
    }

    /**
     * A short request to see that key, credit and model work.
     *
     * @return array{ok: bool, text: string}
     */
    public static function check(): array
    {
        // Kirby may lock the session to renew it; other requests must not wait for Claude.
        Session::unlock();
        try {
            self::request([
                'model' => self::model(),
                'max_tokens' => 64,
                'messages' => [['role' => 'user', 'content' => 'ping']],
            ]);
            return ['ok' => true, 'text' => 'Geprüft am ' . date('d.m.Y') . ' um ' . date('H:i') . ': Claude antwortet.'];
        } catch (Error $e) {
            return ['ok' => false, 'text' => $e->getMessage()];
        }
    }
}
