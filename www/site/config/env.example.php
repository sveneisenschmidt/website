<?php

/**
 * An example of env.php, the configuration of the server. The deploy creates
 * it and sets url and assistantKey (bin/set-env.php). Locally the same values
 * come from .env (see .env.example), and there is no env.php. env.php is in
 * neither git nor the backups, so it must not hold data that a restore needs.
 */

return [
    'debug' => false,
    // The Anthropic API key of the assistant (plugins/assistant). Use a key
    // only for the assistant, with a spending limit. The deploy sets it.
    // Without a key the assistant is off.
    // 'assistantKey' => 'sk-ant-…',
    // The canonical URL of the site on the server. The deploy sets it. Leave
    // it out locally.
    // 'url' => 'https://example.com',
];
