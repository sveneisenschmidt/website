<?php

/**
 * Host-specific configuration. Copy to env.php: make config does
 * that for local development, the deploy does it for the server.
 * env.php is not in git and not in backups. Never put state here.
 */

return [
    'debug' => false,
    // Key of the assistant (plugins/assistant) for the Anthropic API, its own
    // with a spending limit. The deploy sets it; without it the assistant is off.
    // 'assistantKey' => 'sk-ant-…',
    // Canonical site URL on the server. The deploy sets it; leave it out locally.
    // 'url' => 'https://sven.eisenschmidt.website',
];
