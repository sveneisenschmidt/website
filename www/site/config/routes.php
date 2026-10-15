<?php

use Kirby\Cms\Response;
use Website\Blog\Feeds;
use Website\Blog\Likes;
use Website\Blog\Router;

/**
 * The routes that Kirby checks before its own: feed, sitemap, robots.txt and
 * llms.txt (Website\Blog\Feeds), the like button (Website\Blog\Likes), then
 * every other path (Website\Blog\Router).
 */
return [
    // Feed, sitemap and llms.txt read every post, so they are kept in the
    // page cache, which Kirby empties on every change. Feed and sitemap
    // answer If-Modified-Since with 304. Each route names GET|HEAD, because
    // a Kirby route without `method` answers GET only.
    ['pattern' => 'index.xml', 'method' => 'GET|HEAD', 'action' => fn() => Feeds::machineFile('index.xml', 'application/rss+xml', Feeds::rss(...))],
    ['pattern' => 'sitemap.xml', 'method' => 'GET|HEAD', 'action' => fn() => Feeds::machineFile('sitemap.xml', 'application/xml', Feeds::sitemap(...))],
    ['pattern' => 'robots.txt', 'method' => 'GET|HEAD', 'action' => fn() => new Response(Feeds::robots(), 'text/plain')],
    ['pattern' => 'llms.txt', 'method' => 'GET|HEAD', 'action' => fn() => new Response(kirby()->cache('pages')->getOrSet('llms.txt', Feeds::llms(...)), 'text/plain')],
    // Only a POST request adds a like: a crawler follows every link, but it
    // does not send forms.
    [
        'pattern' => '(\d{4}-\d{2}-\d{2})-([a-z0-9-]+)/like',
        'method' => 'POST',
        'action' => fn(string $date, string $slug) => Likes::route($date, $slug),
    ],
    [
        'pattern' => '(:all)',
        'method' => 'GET|HEAD',
        'action' => function (string $path) {
            return Router::resolve($path) ?? $this->next();
        },
    ],
];
