<?php

namespace Website\Assistant;

use RuntimeException;

/**
 * An error the Panel shows, by kind (Panel::api): the kind steers the view,
 * the message is its text. "Nichts geändert" holds for all of them.
 */
final class Error extends RuntimeException
{
    /** The texts by kind. */
    public const TEXTS = [
        'access' => 'Der Schlüssel für Anthropic fehlt oder wird abgelehnt. Nichts geändert. Er steht als assistantKey in env.php.',
        'credit' => 'Das Guthaben bei Anthropic ist leer oder die Ausgabegrenze erreicht. Nichts geändert.',
        'busy' => 'Anthropic antwortet gerade nicht. Nichts geändert, deine Nachricht steht wieder im Eingabefeld.',
        'refusal' => 'Darauf antwortet Claude nicht. Nichts geändert.',
        'rounds' => 'Das hat nicht geklappt. Nichts geändert. Formulier es bitte anders.',
        'chat' => 'Das hat nicht geklappt. Nichts geändert. Beginn am besten einen neuen Chat.',
        'prompt' => 'Der Prompt unter Website › Assistent ist leer, der Assistent ist aus. Nichts geändert.',
        'upload' => 'Die Datei wurde nicht angenommen. Nichts geändert.',
    ];

    public function __construct(public readonly string $kind, string $message = '')
    {
        parent::__construct($message !== '' ? $message : (self::TEXTS[$kind] ?? $kind));
    }
}
