<?php

// Composer installs the dependencies into vendor/, outside the document
// root www/. Kirby's bootstrap does not look there, so this file loads the
// autoloader first.
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/kirby/bootstrap.php';

// The index root is the folder of this file. Without it, Kirby derives the
// root from the location of the autoloader, and a copy of www/ would serve
// the content of the original www/.
echo (new Kirby(['roots' => ['index' => __DIR__]]))->render();
