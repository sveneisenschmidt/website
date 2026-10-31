<?php

// Composer installs the dependencies into vendor/, outside the document
// root www/. Kirby's bootstrap does not look there, so this file loads the
// autoloader first.
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/kirby/bootstrap.php';

// A link from the feed or a social network has parameters such as
// ?source=rss or ?fbclid=…. Without this call Kirby would render such a
// page anew on every visit instead of sending it from the page cache.
Website\Analytics\Campaign::strip();

// The index root is the folder of this file. Without it, Kirby derives the
// root from the location of the autoloader, and a copy of www/ would serve
// the content of the original www/. The root storage is the folder next to
// www/ for files that the web server must not serve and no backup includes:
// the uploads of the assistant, the key of the visitor statistics and,
// locally, the mails of bin/mail-catcher.php. The root countries is the
// folder next to www/ with the country database of the visitor statistics
// (src/Analytics/Country.php).
echo (new Kirby(['roots' => ['index' => __DIR__, 'storage' => dirname(__DIR__) . '/storage', 'countries' => dirname(__DIR__) . '/countries']]))->render();
