<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../www/kirby/bootstrap.php';
// Whoops would replace PHPUnit's error handlers (Kirby's own tests do the same).
Kirby\Cms\App::$enableWhoops = false;
