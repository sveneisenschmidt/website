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
 * request body and returns [HTTP status, decoded response]. The option
 * `blog.assistant.models` does the same for the model list: a closure
 * without arguments.
 */
final class Claude
{
    public const API = 'https://api.anthropic.com/v1/messages';

    /** The Models API. It lists the models the key can use, newest first. */
    public const MODELS_API = 'https://api.anthropic.com/v1/models?limit=100';

    public const DEFAULT_MODEL = 'claude-opus-5-5';

    /**
     * The effort levels of the API (`output_config.effort`), lowest first.
     * The field `assistantEffort` in www/site/plugins/assistant/settings.yml
     * lists the same values with their labels.
     */
    public const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    public const DEFAULT_EFFORT = 'medium';

    /** The version of the Messages API that the request bodies follow. */
    public const API_VERSION = '2023-06-01';

    /** The beta feature that lets the API retry a refused request on another model. */
    public const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /**
     * Seconds one request to Claude may take. One round can take longer than
     * PHP's default limit of 30 s, with Opus or with a web search.
     */
    public const TIMEOUT = 150;

    /** The PHP time limit of a request: the timeout plus 30 s for the rest of the round. */
    public const TIME_LIMIT = self::TIMEOUT + 30;

    public static function key(): ?string
    {
        $key = trim((string) option('assistantKey', ''));
        return $key === '' ? null : $key;
    }

    /**
     * The model chosen under Assistent › Einstellungen, or the default when
     * none is saved. The API decides whether the id exists: a retired model
     * gives the error "model".
     */
    public static function model(): string
    {
        $model = trim((string) site()->content()->get('assistantModel'));
        return $model === '' ? self::DEFAULT_MODEL : $model;
    }

    /** The effort chosen under Assistent › Einstellungen. A saved value that is not in `EFFORTS` gives the default. */
    public static function effort(): string
    {
        $effort = (string) site()->content()->get('assistantEffort');
        return in_array($effort, self::EFFORTS, true) ? $effort : self::DEFAULT_EFFORT;
    }

    /**
     * The models the settings offer, newest first, each with the effort
     * levels it supports. They come from the Models API, so a new model
     * needs no change here. The list holds the models that take the request
     * of `Chat::request()`: `thinking` of the type adaptive and an effort
     * level. When the
     * API does not answer, the list is empty.
     *
     * @return list<array{id: string, name: string, efforts: list<string>}>
     */
    public static function models(): array
    {
        $transport = option('blog.assistant.models');
        if ($transport instanceof Closure) {
            [$status, $response] = $transport();
        } else {
            $key = self::key();
            if ($key === null) {
                return [];
            }
            try {
                $remote = Remote::request(self::MODELS_API, ['headers' => self::headers($key), 'timeout' => 10]);
            } catch (Throwable $e) {
                error_log('blog assistant: Anthropic models ' . $e::class . ': ' . $e->getMessage());
                return [];
            }
            $status = $remote->code();
            $response = json_decode((string) $remote->content(), true) ?? [];
        }
        if ($status !== 200 || !is_array($response['data'] ?? null)) {
            error_log('blog assistant: Anthropic models ' . $status . ' ' . ($response['error']['message'] ?? ''));
            return [];
        }
        $models = [];
        foreach ($response['data'] as $model) {
            $capabilities = $model['capabilities'] ?? [];
            // The API marks each effort level of a model as supported or not.
            $efforts = array_values(array_filter(self::EFFORTS, fn($effort) => ($capabilities['effort'][$effort]['supported'] ?? false) === true));
            if (!is_string($model['id'] ?? null) || $efforts === [] || ($capabilities['thinking']['types']['adaptive']['supported'] ?? false) !== true) {
                continue;
            }
            $models[] = ['id' => $model['id'], 'name' => (string) ($model['display_name'] ?? $model['id']), 'efforts' => $efforts];
        }
        return $models;
    }

    /**
     * The headers of every request to the API.
     *
     * @return list<string>
     */
    private static function headers(string $key): array
    {
        return [
            'x-api-key: ' . $key,
            'anthropic-version: ' . self::API_VERSION,
            'content-type: application/json',
        ];
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
            // When the model refuses the request, the API retries it on a
            // fallback model of its own choice.
            $headers = [...self::headers($key), 'anthropic-beta: ' . self::FALLBACK_BETA];
            $body['fallbacks'] = 'default';
            // A host can disable set_time_limit(); the @ hides the warning, and
            // the limit of the host applies.
            @set_time_limit(self::TIME_LIMIT);
            try {
                $remote = Remote::request(self::API, [
                    'method' => 'POST',
                    'headers' => $headers,
                    'data' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'timeout' => self::TIMEOUT,
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
            // The API does not know the model: it is retired, or the key may
            // not use it.
            $status === 404 => 'model',
            // These requests fail again on every retry: an invalid history, a
            // chat that is too big. The Panel suggests a new chat.
            in_array($status, [400, 413], true) => 'chat',
            default => 'busy',
        });
    }

    /**
     * A short request for the button "Verbindung prüfen" under Assistent ›
     * Einstellungen. It shows whether the key, the credit, the model and the
     * effort work.
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
                // The answer to "ping" needs a few tokens only.
                'max_tokens' => 64,
                // The same fields as in `Chat::request()`, so the check
                // fails when the chat would.
                'thinking' => ['type' => 'adaptive'],
                'output_config' => ['effort' => self::effort()],
                'messages' => [['role' => 'user', 'content' => 'ping']],
            ]);
            return ['ok' => true, 'text' => 'Geprüft am ' . date('d.m.Y') . ' um ' . date('H:i') . ': Claude antwortet.'];
        } catch (Error $e) {
            return ['ok' => false, 'text' => $e->getMessage()];
        }
    }
}
