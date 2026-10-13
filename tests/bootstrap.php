<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../www/kirby/bootstrap.php';
// Whoops would replace PHPUnit's error handlers (Kirby's own tests do the same).
Kirby\Cms\App::$enableWhoops = false;

// Plugin and import functions for unit tests. Kirby loads the plugin with
// include_once when it starts, so nothing is defined twice.
require_once __DIR__ . '/../www/site/plugins/blog/index.php';
require_once __DIR__ . '/../bin/lib/hugo.php';
require_once __DIR__ . '/../bin/lib/seed.php';
require_once __DIR__ . '/../www/site/plugins/assistant/route.php';
require_once __DIR__ . '/../bin/lib/pop.php';
