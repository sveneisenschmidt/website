<?php

use Kirby\Cms\Response;

/**
 * Routes before Kirby's own: the files for machines (plugins/blog/feeds.php),
 * then every other path through the URL scheme of the site (plugins/blog/urls.php).
 */
return [
    // Feed, sitemap and llms.txt read every post: they go into the page
    // cache, which Kirby clears on every change. Feed and sitemap answer
    // If-Modified-Since. Every route here answers HEAD as well: a route of
    // Kirby answers GET only, unless it names its methods.
    ['pattern' => 'index.xml', 'method' => 'GET|HEAD', 'action' => fn() => blog_machine_file('index.xml', 'application/rss+xml', fn() => blog_rss())],
    ['pattern' => 'sitemap.xml', 'method' => 'GET|HEAD', 'action' => fn() => blog_machine_file('sitemap.xml', 'application/xml', fn() => blog_sitemap())],
    ['pattern' => 'robots.txt', 'method' => 'GET|HEAD', 'action' => fn() => new Response(blog_robots(), 'text/plain')],
    ['pattern' => 'llms.txt', 'method' => 'GET|HEAD', 'action' => fn() => new Response(kirby()->cache('pages')->getOrSet('llms.txt', fn() => blog_llms()), 'text/plain')],
    [
        'pattern' => '(:all)',
        'method' => 'GET|HEAD',
        'action' => function (string $path) {
            return blog_resolve($path) ?? $this->next();
        },
    ],
];
