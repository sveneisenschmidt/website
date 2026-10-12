<?php

// Composer keeps the dependencies outside the document root, so Kirby's
// bootstrap does not find them by itself.
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/kirby/bootstrap.php';

// The index root follows this file. Without it Kirby derives it from the
// autoloader's location, and a copy of www/ would serve the original site.
echo (new Kirby(['roots' => ['index' => __DIR__]]))->render();
