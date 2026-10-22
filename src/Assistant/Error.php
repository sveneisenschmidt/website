<?php

namespace Website\Assistant;

use RuntimeException;

/**
 * An error that the assistant view in the Panel shows. `Panel::api()` catches
 * it and returns its `kind` and its message to the view. index.js decides by
 * the kind what the view does: "access" switches the assistant off, and
 * "busy" offers to send the message again. No error changes any content, and
 * each text says so ("Nichts geändert").
 */
final class Error extends RuntimeException
{
    /** The default text of each kind. A caller can pass its own text instead. */
    public const TEXTS = [
        'access' => 'Der Schlüssel für Anthropic fehlt oder wird abgelehnt. Nichts geändert. Er steht als assistantKey in env.php.',
        'credit' => 'Das Guthaben bei Anthropic ist leer oder die Ausgabegrenze erreicht. Nichts geändert.',
        'busy' => 'Anthropic antwortet gerade nicht. Nichts geändert, deine Nachricht steht wieder im Eingabefeld.',
        'refusal' => 'Darauf antwortet Claude nicht. Nichts geändert.',
        'rounds' => 'Claude ist nach ' . Chat::MAX_ROUNDS . ' Runden nicht fertig geworden. Nichts geändert. Formulier es bitte anders.',
        'chat' => 'Das hat nicht geklappt. Nichts geändert. Beginn am besten einen neuen Chat.',
        'prompt' => 'Der Prompt unter Assistent › Einstellungen ist leer, der Assistent ist aus. Nichts geändert.',
        'upload' => 'Die Datei wurde nicht angenommen. Nichts geändert.',
    ];

    public function __construct(public readonly string $kind, string $message = '')
    {
        parent::__construct($message !== '' ? $message : (self::TEXTS[$kind] ?? $kind));
    }
}
