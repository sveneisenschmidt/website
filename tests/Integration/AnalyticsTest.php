<?php

namespace Website\Tests\Integration;

use Website\Analytics\Counter;
use Website\Analytics\Months;
use Website\Analytics\Totals;

final class AnalyticsTest extends IntegrationTestCase
{
    private const SAFARI = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15';

    /** The page cache, as config.php turns it on: the count must work with it. */
    protected array $options = ['cache' => ['pages' => ['active' => true]]];

    private array $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = $_SERVER;
        // The pages render with the default template: they need a home page.
        // The site title comes from TestSite, the section "photos" from post().
        $this->kirby->impersonate('kirby', function () {
            $this->kirby->site()->createChild(['slug' => 'home', 'template' => 'home', 'content' => ['title' => 'Home']])->changeStatus('unlisted');
        });
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_GET = [];
        unset($_COOKIE['kirby_session']);
        parent::tearDown();
    }

    public function testSameBrowserCountsOneVisitAndEveryView(): void
    {
        $this->visit('2026-10-07-test-photo');
        $this->visit('2026-10-07-test-photo');

        $day = $this->today();
        $this->assertSame(1, $day['visits']);
        $this->assertSame(2, $day['views']);
        $this->assertSame(['photos/test-photo' => 1], $day['pages']);
    }

    public function testTwoBrowsersCountTwoVisits(): void
    {
        $this->visit('2026-10-07-test-photo', ip: '203.0.113.1');
        $this->visit('2026-10-07-test-photo', ip: '203.0.113.2');
        $this->visit('photos', ip: '203.0.113.2');

        $day = $this->today();
        $this->assertSame(2, $day['visits']);
        $this->assertSame(3, $day['views']);
        $this->assertSame(['photos/test-photo' => 2, 'photos' => 1], $day['pages']);
    }

    public function testPageFromThePageCacheCounts(): void
    {
        $this->visit('2026-10-07-test-photo', ip: '203.0.113.1');
        $this->assertTrue($this->kirby->cache('pages')->exists('photos/test-photo.latest.html'), 'page is in the page cache');

        $this->visit('2026-10-07-test-photo', ip: '203.0.113.2');

        $this->assertSame(['photos/test-photo' => 2], $this->today()['pages']);
    }

    public function testHomeAndPostPagesCount(): void
    {
        $this->visit('');
        $this->visit('2026-10-07-test-photo');

        $this->assertSame(['home' => 1, 'photos/test-photo' => 1], $this->today()['pages']);
    }

    public function testEveryPostTypeCounts(): void
    {
        $this->post('trips', 'trip', 'a-trip', 'A Trip');
        $this->post('notes', 'note', 'a-note', 'A Note');
        $this->post('essays', 'essay', 'an-essay', 'An Essay');

        foreach (['2026-10-07-a-trip', '2026-10-07-a-note', '2026-10-07-an-essay'] as $path) {
            $this->visit($path);
        }

        $this->assertSame(['trips/a-trip' => 1, 'notes/a-note' => 1, 'essays/an-essay' => 1], $this->today()['pages']);
    }

    public function testDraftAndErrorPageDoNotCount(): void
    {
        $error = $this->kirby->impersonate('kirby', fn() => $this->kirby->site()->createChild(['slug' => 'error', 'template' => 'error', 'content' => ['title' => 'Error']]));
        $draft = $this->post('photos', 'photo', 'draft-photo', 'Draft', 'draft');

        $this->assertNull(Counter::pageId($draft));
        $this->assertNull(Counter::pageId($error));
        $this->assertSame('photos/test-photo', Counter::pageId($this->kirby->page('photos/test-photo')));
    }

    public function testUnreadableMonthIsNotOverwritten(): void
    {
        $file = $this->root . '/content/.analytics/' . date('Y-m') . '.json';
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, '{"broken');
        // Both the count and the Panel log the broken file. The strict
        // phpunit.xml fails on output, so the log goes to a file here.
        $log = $this->root . '/php-errors.log';
        $previous = ini_set('error_log', $log);

        try {
            $this->visit('photos');
            $month = Totals::summary(30)['totals']['month'];
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $this->assertSame('{"broken', file_get_contents($file));
        $this->assertSame(0, $month, 'the Panel still opens');
        $this->assertSame(2, substr_count(file_get_contents($log), 'blog analytics: '), 'the count and the Panel log the broken file');
    }

    public function testBotsLoggedInUsersAndOtherRequestsAddNothing(): void
    {
        $this->visit('photos', ua: 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');
        $this->visit('photos', ua: 'curl/8.7.1');
        $this->visit('photos', ua: '');
        $this->visit('photos', method: 'HEAD');
        $this->visit('photos', session: true);
        $this->visit('does-not-exist');
        $this->visit('robots.txt');

        $this->assertNull($this->today());
    }

    public function testExternalReferrerCountsHostOnceAVisit(): void
    {
        $this->visit('photos', referer: 'https://www.instagram.com/sveneisenschmidt/?igsh=abc');
        $this->visit('2026-10-07-test-photo', referer: 'http://localhost/photos');
        $this->visit('photos', ip: '203.0.113.2', referer: 'http://localhost/');

        $this->assertSame(['instagram.com' => 1], $this->today()['referrers']);
    }

    public function testFeedLinkCountsAsSourceRssOnceAVisit(): void
    {
        $this->visit('2026-10-07-test-photo', query: ['source' => 'rss']);
        $this->visit('photos', query: ['source' => 'rss']);
        $this->visit('photos', ip: '203.0.113.2', referer: 'https://feedly.com/', query: ['source' => 'rss']);
        $this->visit('photos', ip: '203.0.113.3', query: ['source' => 'other']);

        $this->assertSame(['RSS' => 2], $this->today()['referrers']);
        $this->assertSame([['host' => 'RSS', 'visits' => 2]], Totals::summary(30)['referrers']);
    }

    public function testStatsAreStateAndHoldNoIpAddress(): void
    {
        $this->visit('photos', ip: '198.51.100.77');

        $stats = $this->root . '/content/.analytics/' . date('Y-m') . '.json';
        $this->assertFileExists($stats);
        $text = file_get_contents($stats);
        $this->assertStringNotContainsString('198.51.100.77', $text);
        $this->assertStringNotContainsString('Safari', $text);
        $this->assertNotContains('.analytics', $this->kirby->site()->childrenAndDrafts()->pluck('slug'));

        // The daily hashes are generated files, never in a backup.
        foreach (glob($this->root . '/cache/analytics/*') as $file) {
            $this->assertStringNotContainsString('198.51.100.77', file_get_contents($file));
        }
    }

    public function testOldDailyHashesAreDeleted(): void
    {
        $old = $this->root . '/cache/analytics/2020-01-01.json';
        @mkdir(dirname($old), 0777, true);
        file_put_contents($old, '{}');
        file_put_contents($old . '.1a2b3c4d.tmp', '{}');

        $this->visit('photos');

        $this->assertFileDoesNotExist($old);
        $this->assertFileDoesNotExist($old . '.1a2b3c4d.tmp');
        $this->assertFileExists($this->root . '/cache/analytics/' . date('Y-m-d') . '.json');
    }

    public function testSummaryForThePanel(): void
    {
        $this->visit('2026-10-07-test-photo', referer: 'https://instagram.com/');
        $this->visit('photos', ip: '203.0.113.2');
        $this->visit('2026-10-07-test-photo', ip: '203.0.113.2');

        $summary = Totals::summary(30);

        $this->assertCount(30, $summary['days']);
        $this->assertSame(['date' => date('Y-m-d'), 'visits' => 2, 'views' => 3], end($summary['days']));
        $this->assertSame(0, $summary['days'][0]['visits']);
        $this->assertSame(['today' => 2, 'week' => 2, 'month' => 2, 'views' => 3], $summary['totals']);
        $this->assertSame([
            ['id' => 'photos/test-photo', 'title' => 'Test Photo', 'url' => 'http://localhost/2026-10-07-test-photo/', 'visits' => 2],
            ['id' => 'photos', 'title' => 'Photos', 'url' => 'http://localhost/photos/', 'visits' => 1],
        ], $summary['pages']);
        $this->assertSame([['host' => 'instagram.com', 'visits' => 1]], $summary['referrers']);
    }

    public function testSummaryNamesHomeRenamedAndDeletedPages(): void
    {
        $this->visit('2026-10-07-test-photo');
        $this->visit('');
        $this->kirby->impersonate('kirby', fn() => $this->kirby->page('photos/test-photo')->changeTitle('Renamed Photo'));
        $this->kirby->impersonate('kirby', fn() => $this->kirby->site()->createChild(['slug' => 'gone', 'template' => 'default', 'content' => ['title' => 'Gone']])->changeStatus('listed'));
        $this->visit('gone');
        $this->kirby->impersonate('kirby', fn() => $this->kirby->page('gone')->delete(true));

        $titles = array_column(Totals::summary(30)['pages'], 'title', 'id');

        $this->assertSame('Renamed Photo', $titles['photos/test-photo']);
        $this->assertSame('Startseite', $titles['home']);
        $this->assertSame('gone (gelöscht)', $titles['gone']);
    }

    public function testParallelViewsLoseNoCount(): void
    {
        $workers = 8;
        $views = 5;
        $procs = [];
        $pipes = [];
        for ($i = 1; $i <= $workers; $i++) {
            $cmd = [PHP_BINARY, __DIR__ . '/../Support/visit-worker.php', $this->root, "203.0.113.$i", (string) $views];
            $procs[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
        }
        $results = [];
        foreach ($procs as $i => $proc) {
            $results[$i] = trim(stream_get_contents($pipes[$i][1]) . stream_get_contents($pipes[$i][2]));
            proc_close($proc);
        }

        $this->assertSame(array_fill(1, $workers, 'ok'), $results);
        $day = $this->today();
        $this->assertSame($workers, $day['visits']);
        $this->assertSame($workers * $views, $day['views']);
        $this->assertSame(['photos' => $workers], $day['pages']);
    }

    private function visit(
        string $path,
        string $ip = '203.0.113.1',
        string $ua = self::SAFARI,
        ?string $referer = null,
        string $method = 'GET',
        bool $session = false,
        array $query = [],
    ): void {
        $_GET = $query;
        $_SERVER['REMOTE_ADDR'] = $ip;
        $_SERVER['HTTP_USER_AGENT'] = $ua;
        $_SERVER['HTTP_HOST'] = 'localhost';
        unset($_SERVER['HTTP_REFERER']);
        if ($referer !== null) {
            $_SERVER['HTTP_REFERER'] = $referer;
        }
        if ($session) {
            $_COOKIE['kirby_session'] = 'x';
        } else {
            unset($_COOKIE['kirby_session']);
        }
        $this->kirby->render($path, $method);
    }

    private function today(): ?array
    {
        return Months::read(date('Y-m'))[date('Y-m-d')] ?? null;
    }
}
