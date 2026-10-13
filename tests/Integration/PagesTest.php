<?php

namespace Website\Tests\Integration;

use Website\Tests\Support\TestSite;

/**
 * Home, archive, About and the error page.
 */
final class PagesTest extends IntegrationTestCase
{
    /**
     * About must stay out of the page cache, so it is on here.
     *
     * @var array<mixed>
     */
    protected array $options = ['cache' => ['pages' => ['active' => true]]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->kirby->impersonate('kirby', function () {
            $site = $this->kirby->site();
            // Kirby does not change the status of these pages: they are created unlisted.
            $site->createChild(['slug' => 'home', 'template' => 'home', 'draft' => false, 'content' => ['title' => 'Home']]);
            $site->createChild(['slug' => 'about', 'template' => 'about', 'draft' => false, 'content' => ['title' => 'About', 'label' => 'Personal blog', 'lastmod' => '2026-09-12', 'text' => 'Hello.']]);
            $archive = $site->createChild(['slug' => 'archive', 'template' => 'archive', 'draft' => false, 'content' => ['title' => 'Archive', 'stream' => 'all']]);
            $archive->createChild(['slug' => 'photos', 'template' => 'archive', 'draft' => false, 'content' => ['title' => 'Archive · Photos', 'stream' => 'photos']]);
        });
        $cover = $this->upload($this->jpeg(8, 8), 'cover.jpg');
        $this->post = $this->kirby->impersonate('kirby', fn() => $this->post->update(['cover' => [$cover->uuid()->toString()], 'pick' => 'true']));
    }

    /** Job title and employer come from About; an empty field leaves its key out. */
    public function testThePersonOnTheHomePageTakesTheJobFromAbout(): void
    {
        $person = fn() => json_decode(preg_match('!<script type="application/ld\+json">(.*?)</script>!s', $this->kirby->site()->homePage()->render(), $m) ? $m[1] : 'null', true);

        $this->assertArrayNotHasKey('jobTitle', $person());
        $this->assertArrayNotHasKey('worksFor', $person());

        $this->kirby->impersonate('kirby', fn() => $this->kirby->page('about')->update(['jobtitle' => 'CTO', 'employer' => 'roadsurfer', 'employerurl' => 'https://roadsurfer.com']));
        $this->kirby->cache('pages')->flush();

        $this->assertSame('CTO', $person()['jobTitle']);
        $this->assertSame(['@type' => 'Organization', 'name' => 'roadsurfer', 'url' => 'https://roadsurfer.com'], $person()['worksFor']);
    }

    public function testHomeShowsThePickAndTheTimeline(): void
    {
        $html = $this->kirby->site()->homePage()->render();

        $this->assertStringContainsString('<title>Sven Eisenschmidt - Personal Blog</title>', $html);
        $this->assertStringContainsString('<body class="home">', $html);
        $this->assertStringContainsString('<h1 class="masthead">', $html);
        $this->assertStringContainsString('<h3><a href="' . $this->post->url() . '">Test Photo</a></h3>', $html);
        $this->assertStringContainsString('alt="Test Photo"', $html, 'a cover without alt text takes the title');
        $this->assertStringContainsString('fetchpriority="high"', $html);
        $this->assertStringContainsString('data-fit=".pictures"', $html);
        $this->assertStringContainsString('<span class="stream-count">1</span>', $html);
        $this->assertStringContainsString('<span class="writing-label">Latest:</span> Test Photo', $html);
        $this->assertStringContainsString('<p class="excerpt">Test intro</p>', $html);
        $this->assertStringContainsString('.writing[data-fit]', $html, 'fit.js');
        $this->assertStringContainsString('<meta property="og:image" content="', $html);
    }

    public function testArchiveListsByMonth(): void
    {
        $html = $this->kirby->page('archive')->render();

        $this->assertStringContainsString('<body class="archive">', $html);
        $this->assertStringContainsString('<a href="/archive/" aria-current="true">All</a>', $html);
        $this->assertStringContainsString("<h2 class=\"archive-month stream-title\">\n<span>October 2026</span>\n<span>1</span>\n</h2>", $html);
        $this->assertStringContainsString('<a class="archive-title" href="' . $this->post->url() . '">Test Photo</a>', $html);
        $this->assertStringContainsString('<span class="thumb">', $html);
    }

    public function testPhotosAreASheetWithTheHeartOnPicks(): void
    {
        $html = $this->kirby->page('archive/photos')->render();

        $this->assertStringContainsString('<a href="/archive/photos/" aria-current="true">Photos</a>', $html);
        $this->assertStringContainsString('<ul class="archive-sheet">', $html);
        $this->assertStringContainsString('<span class="tile-pick"><svg class="icon-heart"', $html);
    }

    public function testAboutShowsTheVisitorNumbersAndStaysOutOfTheCache(): void
    {
        $stats = $this->root . '/content/.analytics/2026-10.json';
        mkdir(dirname($stats), 0777, true);
        file_put_contents($stats, json_encode([
            '2026-10-07' => ['visits' => 2, 'views' => 3, 'pages' => ['photos/test-photo' => 2, 'home' => 1], 'referrers' => []],
            '2026-10-08' => ['visits' => 1, 'views' => 1, 'pages' => ['photos/test-photo' => 1], 'referrers' => []],
        ]));

        $html = $this->kirby->render('about')->body();

        $this->assertStringContainsString('<p class="stream-title stream-opening">Personal blog', $html);
        $this->assertStringContainsString('Last updated: <time datetime="2026-09-12">September 12, 2026</time>', $html);
        $this->assertStringContainsString('<strong>3</strong> visits and <strong>4</strong> page views across <strong>2</strong> pages.', $html);
        $this->assertStringContainsString('<li><a href="' . $this->post->url() . '">Test Photo</a> - 3 visits</li>', $html);
        $this->assertStringContainsString('<section class="writing writing-aside"', $html);
        $this->assertFalse($this->kirby->cache('pages')->exists('about.latest.html'));
        $this->assertFalse($this->kirby->page('about')->isCacheable(), 'cache.pages.ignore in config.php');
        $this->assertTrue($this->kirby->page('archive')->isCacheable());
    }

    public function testErrorPage(): void
    {
        // Kirby changes the status of no error page: a folder, as the import writes it.
        mkdir($this->root . '/content/error');
        file_put_contents($this->root . '/content/error/error.txt', "Title: 404 Page not found\n");

        $response = TestSite::app($this->root, $this->options)->render('does-not-exist');

        $this->assertSame(404, $response->code());
        $this->assertStringContainsString('<title>404 Page not found · Sven Eisenschmidt - Personal Blog</title>', $response->body());
        $this->assertStringContainsString('<p class="stream-title stream-opening">Error 404</p>', $response->body());
    }
}
