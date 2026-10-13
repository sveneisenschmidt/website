<?php

use Kirby\Cms\Response;
use Website\Blog\Feeds;
use Website\Blog\Router;

/**
 * Routes before Kirby's own: the files for machines (Website\Blog\Feeds),
 * then every other path through the URL scheme of the site (Website\Blog\Router).
 */
return [
    // Feed, sitemap and llms.txt read every post: they go into the page
    // cache, which Kirby clears on every change. Feed and sitemap answer
    // If-Modified-Since. Every route here answers HEAD as well: a route of
    // Kirby answers GET only, unless it names its methods.
    ['pattern' => 'index.xml', 'method' => 'GET|HEAD', 'action' => fn() => Feeds::machineFile('index.xml', 'application/rss+xml', Feeds::rss(...))],
    ['pattern' => 'sitemap.xml', 'method' => 'GET|HEAD', 'action' => fn() => Feeds::machineFile('sitemap.xml', 'application/xml', Feeds::sitemap(...))],
    ['pattern' => 'robots.txt', 'method' => 'GET|HEAD', 'action' => fn() => new Response(Feeds::robots(), 'text/plain')],
    ['pattern' => 'llms.txt', 'method' => 'GET|HEAD', 'action' => fn() => new Response(kirby()->cache('pages')->getOrSet('llms.txt', Feeds::llms(...)), 'text/plain')],
    [
        'pattern' => '(:all)',
        'method' => 'GET|HEAD',
        'action' => function (string $path) {
            return Router::resolve($path) ?? $this->next();
        },
    ],
];
