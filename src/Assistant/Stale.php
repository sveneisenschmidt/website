<?php

namespace Website\Assistant;

use RuntimeException;

/**
 * Thrown by `Apply` when a card no longer matches the content, for example
 * because the field was edited after Claude proposed the change. A card is a
 * change that Claude proposed; the chat shows it with buttons to apply or
 * discard it. `Apply` then changes nothing. The card shows the German
 * message to the Panel user, and Claude gets the English reason with the
 * next message.
 */
final class Stale extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
