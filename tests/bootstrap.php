<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../www/kirby/bootstrap.php';
// Whoops, the error page of Kirby, would replace PHPUnit's error handlers.
// Kirby's own tests turn it off the same way.
Kirby\Cms\App::$enableWhoops = false;
