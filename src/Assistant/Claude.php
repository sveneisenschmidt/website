<?php

namespace Website\Assistant;

use Closure;
use Kirby\Http\Remote;
use Throwable;

/**
 * Sends one request to the Anthropic Messages API and turns every failure
 * into an `Error` for the Panel. The request is plain HTTP through Kirby's
 * `Remote`, so the site needs no Anthropic SDK in composer.json. The API key
 * is the option `assistantKey` in www/site/config/env.php; bin/deploy.sh
 * writes it from the secret ANTHROPIC_API_KEY. Tests replace the HTTP request
 * with the option `blog.assistant.transport`: a closure that takes the
 * request body and returns [HTTP status, decoded response].
 */
final class Claude
{
    public const API = 'https://api.anthropic.com/v1/messages';

    /**
     * The models the settings offer. The field `assistantModel` in
     * www/site/blueprints/tabs/assistant.yml lists the same ids.
     */
    public const MODELS = [
        'claude-opus-5-5' => 'Claude Opus 5.5',
        'claude-sonnet-5-5' => 'Claude Sonnet 5.5',
        'claude-haiku-5-5' => 'Claude Haiku 5.5',
    ];

    public const DEFAULT_MODEL = 'claude-opus-5-5';

    /**
     * True for Opus and Sonnet. Only these models support the server-side
     * fallback after a refusal and the web search with dynamic filtering
     * (`web_search_20260209`). Haiku supports neither.
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

    /** The model chosen under Website › Assistent. A saved id that is not in `MODELS` gives the default. */
    public static function model(): string
    {
        $model = (string) site()->content()->get('assistantModel');
        return array_key_exists($model, self::MODELS) ? $model : self::DEFAULT_MODEL;
    }

    /**
     * Sends one request body and returns the decoded response. Every failure
     * throws an `Error` whose kind tells the Panel what to show.
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
            // When the model refuses the request, the API retries it on a
            // fallback model of its own choice.
            if (self::fullModel($body['model'])) {
                $headers[] = 'anthropic-beta: server-side-fallback-2026-07-01';
                $body['fallbacks'] = 'default';
            }
            // One round can take longer than PHP's default limit of 30 s, with
            // Opus or with a web search. A host can disable set_time_limit();
            // the @ hides the warning, and the limit of the host applies.
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
            // A response without a list of typed content blocks cannot go into
            // the history. It counts as "busy", and nothing changes.
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
            // These requests fail again on every retry: an invalid history, a
            // retired model, a chat that is too big. The Panel suggests a new chat.
            in_array($status, [400, 404, 413], true) => 'chat',
            default => 'busy',
        });
    }

    /**
     * A short request for the button "Verbindung prüfen" under Website ›
     * Assistent. It shows whether the key, the credit and the model work.
     *
     * @return array{ok: bool, text: string}
     */
    public static function check(): array
    {
        // Kirby can lock the session to renew it during this request. Unlock
        // it first, so other requests of the browser do not wait for Claude.
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
