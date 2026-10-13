<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../www/kirby/bootstrap.php';
// Whoops would replace PHPUnit's error handlers (Kirby's own tests do the same).
Kirby\Cms\App::$enableWhoops = false;

// Plugin functions for unit tests. Kirby loads the plugin with include_once
// when it starts, so nothing is defined twice. The blog's classes in src/
// come from Composer's autoloader.
require_once __DIR__ . '/../www/site/plugins/assistant/route.php';
