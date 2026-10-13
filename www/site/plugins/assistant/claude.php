<?php

use Kirby\Http\Remote;

/**
 * One request to the Claude Messages API. Raw HTTP through Kirby's Remote,
 * as on concertshotsbyluisa.de: the deploy installs no SDK.
 * The key lives in env.php ('assistantKey'), set by the deploy.
 * Tests swap the transport with the option 'blog.assistant.transport':
 * a closure that takes the request body and returns [status, body].
 */

const BLOG_ASSISTANT_API = 'https://api.anthropic.com/v1/messages';

const BLOG_ASSISTANT_MODELS = [
    'claude-opus-5-5' => 'Claude Opus 5.5',
    'claude-sonnet-5-5' => 'Claude Sonnet 5.5',
    'claude-haiku-5-5' => 'Claude Haiku 5.5',
];

const BLOG_ASSISTANT_DEFAULT_MODEL = 'claude-opus-5-5';

/**
 * Opus and Sonnet: the server-side fallback and the web search with
 * dynamic filtering. Haiku has neither.
 */
function blog_assistant_full_model(string $model): bool
{
    return !str_contains($model, 'haiku');
}

/** Errors the Panel shows, by kind. "Nichts geändert" holds for all of them. */
const BLOG_ASSISTANT_ERRORS = [
    'access' => 'Der Schlüssel für Anthropic fehlt oder wird abgelehnt. Nichts geändert. Er steht als assistantKey in env.php.',
    'credit' => 'Das Guthaben bei Anthropic ist leer oder die Ausgabegrenze erreicht. Nichts geändert.',
    'busy' => 'Anthropic antwortet gerade nicht. Nichts geändert, deine Nachricht steht wieder im Eingabefeld.',
    'refusal' => 'Darauf antwortet Claude nicht. Nichts geändert.',
    'rounds' => 'Das hat nicht geklappt. Nichts geändert. Formulier es bitte anders.',
    'chat' => 'Das hat nicht geklappt. Nichts geändert. Beginn am besten einen neuen Chat.',
    'prompt' => 'Der Prompt unter Website › Assistent ist leer, der Assistent ist aus. Nichts geändert.',
    'upload' => 'Die Datei wurde nicht angenommen. Nichts geändert.',
];

final class BlogAssistantError extends RuntimeException
{
    public function __construct(public readonly string $kind, string $message = '')
    {
        parent::__construct($message !== '' ? $message : (BLOG_ASSISTANT_ERRORS[$kind] ?? $kind));
    }
}

function blog_assistant_key(): ?string
{
    $key = trim((string)option('assistantKey', ''));
    return $key === '' ? null : $key;
}

/** The model from Website › Assistent, or the default when the saved one is gone. */
function blog_assistant_model(): string
{
    $model = (string)site()->content()->get('assistantModel');
    return array_key_exists($model, BLOG_ASSISTANT_MODELS) ? $model : BLOG_ASSISTANT_DEFAULT_MODEL;
}

/**
 * Sends one request body, returns the decoded response.
 * Throws BlogAssistantError with the kind the Panel needs.
 */
function blog_assistant_http(array $body): array
{
    $transport = option('blog.assistant.transport');
    if ($transport instanceof Closure) {
        [$status, $response] = $transport($body);
    } else {
        $key = blog_assistant_key() ?? throw new BlogAssistantError('access');
        $headers = [
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ];
        // A refused request falls back server-side.
        if (blog_assistant_full_model($body['model'])) {
            $headers[] = 'anthropic-beta: server-side-fallback-2026-07-01';
            $body['fallbacks'] = 'default';
        }
        // One round can take longer than PHP's default 30 s (Opus, a web search).
        // set_time_limit may be disabled on a host; then its limit stays.
        @set_time_limit(180);
        try {
            $remote = Remote::request(BLOG_ASSISTANT_API, [
                'method' => 'POST',
                'headers' => $headers,
                'data' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'timeout' => 150,
            ]);
        } catch (Throwable $e) {
            error_log('blog assistant: Anthropic ' . $e::class . ': ' . $e->getMessage());
            throw new BlogAssistantError('busy');
        }
        $status = $remote->code();
        $response = json_decode((string)$remote->content(), true) ?? [];
    }

    if ($status === 200) {
        if (($response['stop_reason'] ?? null) === 'refusal') {
            throw new BlogAssistantError('refusal');
        }
        // An answer without a list of blocks is no answer: nothing goes into the chat.
        $content = $response['content'] ?? null;
        if (!is_array($content) || $content === [] || !array_is_list($content) || array_filter($content, fn ($block) => !is_string($block['type'] ?? null)) !== []) {
            error_log('blog assistant: Anthropic 200 without content');
            throw new BlogAssistantError('busy');
        }
        return $response;
    }
    $message = (string)($response['error']['message'] ?? '');
    error_log('blog assistant: Anthropic ' . $status . ' ' . $message);
    throw new BlogAssistantError(match (true) {
        $status === 401, $status === 403 => 'access',
        $status === 402, $status === 400 && stripos($message, 'credit') !== false => 'credit',
        // A request that will never work: invalid history, retired model, chat too big.
        in_array($status, [400, 404, 413], true) => 'chat',
        default => 'busy',
    });
}
