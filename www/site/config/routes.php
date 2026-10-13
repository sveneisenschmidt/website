<?php

use Kirby\Cms\Response;

/**
 * Routes before Kirby's own: the files for machines (plugins/blog/feeds.php),
 * then every other path through the URLs of Hugo (plugins/blog/urls.php).
 */
return [
    // Feed and sitemap take a second to build: they go into the page cache,
    // which Kirby clears on every change.
    ['pattern' => 'index.xml', 'action' => fn () => new Response(kirby()->cache('pages')->getOrSet('index.xml', fn () => blog_rss()), 'application/rss+xml')],
    ['pattern' => 'sitemap.xml', 'action' => fn () => new Response(kirby()->cache('pages')->getOrSet('sitemap.xml', fn () => blog_sitemap()), 'application/xml')],
    ['pattern' => 'robots.txt', 'action' => fn () => new Response(blog_robots(), 'text/plain')],
    ['pattern' => 'llms.txt', 'action' => fn () => new Response(blog_llms(), 'text/plain')],
    [
        'pattern' => '(:all)',
        'action' => function (string $path) {
            return blog_route($path) ?? $this->next();
        },
    ],
];
