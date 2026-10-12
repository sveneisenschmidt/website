<?php

/**
 * Host-specific configuration. Copy to env.php: make kirby-config does
 * that for local development, the deploy does it for the server.
 * env.php is not in git and not in backups. Never put state here.
 */

return [
    'debug' => false,
    // true: search engines may index the site. The deploy sets it.
    'public' => false,
    // Canonical site URL on the server. The deploy sets it; leave it out locally.
    // 'url' => 'https://sven.eisenschmidt.website',
];
