<?php

namespace Website\Assistant;

use RuntimeException;

/**
 * A card that no longer fits the content: nothing changed (Apply). The
 * message is for the Panel, the reason for Claude.
 */
final class Stale extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
