<?php

namespace Website\Tests\Integration;

use PDO;
use Pdo\Sqlite;
use Website\Analytics\Campaign;
use Website\Analytics\Counter;
use Website\Analytics\Hits;
use Website\Analytics\Totals;

final class AnalyticsTest extends IntegrationTestCase
{
    private const SAFARI = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15';
    private const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    /**
     * The page cache is on, as config.php turns it on for the live site. The
     * count must work for a page from the cache too.
     *
     * @var array<mixed>
     */
    protected array $options = ['cache' => ['pages' => ['active' => true]]];

    /** @var array<mixed> */
    private array $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = $_SERVER;
        // The pages render with the template default, which needs a home page.
        // TestSite gives the site title, and post() the section "photos".
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
        $this->assertSame(['photos' => 1, 'photos/test-photo' => 2], $day['pages']);
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

        $this->assertSame(['essays/an-essay' => 1, 'notes/a-note' => 1, 'trips/a-trip' => 1], $this->today()['pages']);
    }

    public function testDraftAndErrorPageHaveNoPageId(): void
    {
        $error = $this->kirby->impersonate('kirby', fn() => $this->kirby->site()->createChild(['slug' => 'error', 'template' => 'error', 'content' => ['title' => 'Error']]));
        $draft = $this->post('photos', 'photo', 'draft-photo', 'Draft', 'draft');

        $this->assertNull(Counter::pageId($draft));
        $this->assertNull(Counter::pageId($error));
        $this->assertSame('photos/test-photo', Counter::pageId($this->kirby->page('photos/test-photo')));
    }

    public function testEveryRequestIsRecorded(): void
    {
        $this->visit('photos', ua: self::GOOGLEBOT, browser: false);
        $this->visit('photos', ua: 'curl/8.7.1', browser: false);
        $this->visit('photos', method: 'HEAD');
        $this->visit('does-not-exist');
        $this->visit('robots.txt');
        $this->visit('photos', session: true);

        $this->assertSame([
            ['method' => 'GET', 'uri' => '/photos/', 'page' => 'photos', 'status' => 200, 'session' => 0, 'agent' => self::GOOGLEBOT],
            ['method' => 'GET', 'uri' => '/photos/', 'page' => 'photos', 'status' => 200, 'session' => 0, 'agent' => 'curl/8.7.1'],
            ['method' => 'HEAD', 'uri' => '/photos/', 'page' => 'photos', 'status' => 200, 'session' => 0, 'agent' => self::SAFARI],
            ['method' => 'GET', 'uri' => '/does-not-exist/', 'page' => null, 'status' => 404, 'session' => 0, 'agent' => self::SAFARI],
            ['method' => 'GET', 'uri' => '/robots.txt', 'page' => null, 'status' => 200, 'session' => 0, 'agent' => self::SAFARI],
            ['method' => 'GET', 'uri' => '/photos/', 'page' => 'photos', 'status' => 200, 'session' => 1, 'agent' => self::SAFARI],
        ], $this->rows('method, uri, page, status, session, agent'));
        $this->assertNull($this->today(), 'no request is a page view of a person');
    }

    public function testARedirectIsStoredWithItsStatusAndCountsNoView(): void
    {
        file_put_contents($this->root . '/content/.redirects.json', json_encode([['from' => 'old-photos', 'to' => '/photos/']]));

        $this->visit('old-photos');

        $this->assertSame([['uri' => '/old-photos/', 'page' => null, 'status' => 301]], $this->rows('uri, page, status'));
        $this->assertNull($this->today());
    }

    public function testEveryHeaderIsStoredWithTimeQueryAndProtocol(): void
    {
        $this->visit('photos', query: ['source' => 'rss', 'utm_medium' => 'social'], headers: ['HTTP_SEC_CH_UA' => '"Chromium";v="141"', 'HTTP_DNT' => '1']);

        $row = $this->rows('time, uri, protocol, headers')[0];
        $headers = json_decode((string) $row['headers'], true);
        $this->assertMatchesRegularExpression('/^' . date('Y-m-d') . 'T\d\d:\d\d:\d\d\.\d{3}[+-]\d\d:\d\d$/', $row['time']);
        $this->assertSame('/photos/?source=rss&utm_medium=social', $row['uri']);
        $this->assertSame('HTTP/2.0', $row['protocol']);
        $this->assertSame('"Chromium";v="141"', $headers['sec-ch-ua']);
        $this->assertSame('1', $headers['dnt']);
        $this->assertSame(self::SAFARI, $headers['user-agent']);
        $this->assertSame('document', $headers['sec-fetch-dest']);
    }

    public function testClickIdsAreStoredWithoutTheirValue(): void
    {
        $this->visit('photos', referer: 'https://www.instagram.com/example/?igsh=abc123&utm_medium=share', query: ['fbclid' => 'IwAR3xyz', 'utm_source' => 'instagram']);

        $row = $this->rows('uri, referer')[0];
        $this->assertSame('/photos/?fbclid=&utm_source=instagram', $row['uri']);
        $this->assertSame('https://www.instagram.com/example/?igsh=&utm_medium=share', $row['referer']);
        $this->assertSame(['instagram.com' => 1], $this->today()['referrers']);
        $this->assertSame('/a/?GCLID=&b=1#top', Counter::withoutClickIds('/a/?GCLID=x&b=1#top'));
        $this->assertSame('/a/', Counter::withoutClickIds('/a/'));
    }

    public function testNoIpAddressAndNoLoginSecretIsStored(): void
    {
        $this->visit('photos', ip: '198.51.100.77', headers: [
            'HTTP_COOKIE' => 'kirby_session=secret-session',
            'HTTP_AUTHORIZATION' => 'Basic c2VjcmV0LXBhc3N3b3Jk',
            'HTTP_X_FORWARDED_FOR' => '192.0.2.9, 198.51.100.77',
            'HTTP_X_REAL_IP' => '192.0.2.10',
            'HTTP_X_ODD_HEADER' => 'seen from 198.51.100.77',
        ]);

        $files = [$this->database(), $this->root . '/storage/analytics.key'];
        foreach ($files as $file) {
            $text = (string) file_get_contents($file);
            foreach (['198.51.100.77', '192.0.2.9', '192.0.2.10', 'secret-session', 'c2VjcmV0LXBhc3N3b3Jk'] as $secret) {
                $this->assertStringNotContainsString($secret, $text, basename($file));
            }
        }
        $this->assertNotContains('.analytics', $this->kirby->site()->childrenAndDrafts()->pluck('slug'));
    }

    public function testOwnAddressesCountForNothingAlsoAfterwards(): void
    {
        $this->visit('photos', ip: '198.51.100.7');
        $this->visit('photos', ip: '2001:db8:1:2::abcd');
        $this->visit('photos', ip: '203.0.113.9');
        $this->assertSame(3, $this->today()['visits']);

        $this->kirby->impersonate('kirby', fn() => $this->kirby->site()->update(['ownIps' => "198.51.100.7\n2001:db8:1:2::/64"]));
        $this->visit('photos', ip: '2001:db8:1:2::ffff');

        $this->assertSame(1, $this->today()['visits']);
        $this->assertCount(4, $this->rows('uri'), 'the requests stay in the database');
    }

    public function testThePanelItsApiAndThumbnailsAreNotRecorded(): void
    {
        $this->assertFalse(Counter::isRecorded('panel/pages/photos'));
        $this->assertFalse(Counter::isRecorded('api/site'));
        $this->assertFalse(Counter::isRecorded('media/pages/photos/abc/photo-1200x.jpg'));
        $this->assertTrue(Counter::isRecorded('photos'));
        $this->assertTrue(Counter::isRecorded(''));
    }

    public function testABrowserNameWithoutBrowserHeadersIsNoPageView(): void
    {
        $this->visit('photos', browser: false);

        $this->assertNull($this->today());
        $this->assertCount(1, $this->rows('uri'));
    }

    public function testAPrefetchIsNoPageView(): void
    {
        $this->visit('photos', headers: ['HTTP_SEC_PURPOSE' => 'prefetch']);

        $this->assertNull($this->today());
        $this->assertCount(1, $this->rows('uri'));
    }

    public function testExternalReferrerCountsHostOnceAVisit(): void
    {
        $this->visit('photos', referer: 'https://www.instagram.com/example/?igsh=abc');
        $this->visit('2026-10-07-test-photo', referer: 'http://localhost/photos');
        $this->visit('photos', ip: '203.0.113.2', referer: 'http://localhost/');

        $this->assertSame(['instagram.com' => 1], $this->today()['referrers']);
    }

    public function testTheOriginOfAVisitIsItsReferrerElseTheFeed(): void
    {
        $this->visit('2026-10-07-test-photo', query: ['source' => 'rss']);
        $this->visit('photos', query: ['source' => 'rss']);
        $this->visit('photos', ip: '203.0.113.2', referer: 'https://kagi.com/', query: ['source' => 'rss']);
        $this->visit('photos', ip: '203.0.113.3', query: ['source' => 'other']);

        $this->assertSame(['RSS' => 1, 'kagi.com' => 1], $this->today()['referrers']);
    }

    public function testUnreadableDatabaseIsNotOverwritten(): void
    {
        $file = $this->database();
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, 'broken');
        // phpunit.xml fails a test on any output, so the log goes to a file here.
        $log = $this->root . '/php-errors.log';
        $previous = ini_set('error_log', $log);

        try {
            $this->visit('photos');
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $this->assertSame('broken', file_get_contents($file));
        $this->assertStringContainsString('blog analytics: ', (string) file_get_contents($log));
    }

    public function testSummaryForThePanel(): void
    {
        $this->visit('2026-10-07-test-photo', referer: 'https://instagram.com/');
        $this->visit('photos', ip: '203.0.113.2');
        $this->visit('2026-10-07-test-photo', ip: '203.0.113.2');
        $this->visit('photos', ip: '203.0.113.3', ua: self::GOOGLEBOT, browser: false);
        $this->visit('photos', ip: '203.0.113.4', browser: false);

        $summary = Totals::summary(30);
        $pages = [
            ['id' => 'photos/test-photo', 'title' => 'Test Photo', 'url' => 'http://localhost/2026-10-07-test-photo/', 'visits' => 2],
            ['id' => 'photos', 'title' => 'Photos', 'url' => 'http://localhost/photos/', 'visits' => 1],
        ];
        $referrers = [['host' => 'instagram.com', 'visits' => 1]];

        $this->assertCount(30, $summary['days']);
        $this->assertSame(['date' => date('Y-m-d'), 'visits' => 2, 'views' => 3, 'pages' => $pages, 'referrers' => $referrers], end($summary['days']));
        $this->assertSame(['date' => date('Y-m-d', strtotime('-29 days')), 'visits' => 0, 'views' => 0, 'pages' => [], 'referrers' => []], $summary['days'][0]);
        $this->assertSame(['today' => 2, 'week' => 2, 'month' => 2, 'views' => 3], $summary['totals']);
        $this->assertSame($pages, $summary['pages']);
        $this->assertSame($referrers, $summary['referrers']);
        $this->assertSame(['named' => 1, 'suspect' => 1, 'names' => [['name' => 'Googlebot', 'hits' => 1]]], $summary['bots']);
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

    public function testOldMonthFilesAndRequestsAddUpAlsoPerDay(): void
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        foreach ([date('Y-m', strtotime('-1 day')), date('Y-m')] as $month) {
            $days = array_filter([
                $yesterday => ['visits' => 2, 'views' => 2, 'pages' => ['photos/test-photo' => 2], 'referrers' => ['RSS' => 1]],
                date('Y-m-d') => ['visits' => 3, 'views' => 5, 'pages' => ['photos' => 3], 'referrers' => ['RSS' => 2]],
            ], fn($date) => str_starts_with($date, $month), ARRAY_FILTER_USE_KEY);
            $file = $this->root . "/content/.analytics/$month.json";
            @mkdir(dirname($file), 0777, true);
            file_put_contents($file, json_encode($days));
        }

        $this->visit('photos', referer: 'https://kagi.com/');

        $summary = Totals::summary(30);
        $this->assertSame(['today' => 4, 'week' => 6, 'month' => 6, 'views' => 8], $summary['totals']);
        $this->assertSame([['host' => 'RSS', 'visits' => 3], ['host' => 'kagi.com', 'visits' => 1]], $summary['referrers']);
        $this->assertSame([['host' => 'RSS', 'visits' => 1]], $summary['days'][28]['referrers']);
        $this->assertSame('photos/test-photo', $summary['days'][28]['pages'][0]['id']);
        $this->assertSame([['host' => 'RSS', 'visits' => 2], ['host' => 'kagi.com', 'visits' => 1]], $summary['days'][29]['referrers']);
        $this->assertSame(['visits' => 6, 'views' => 8, 'pages' => 2, 'top' => ['photos/test-photo' => 2]], Totals::all());
    }

    public function testAnUnreadableMonthFileIsLoggedAndThePanelOpens(): void
    {
        $file = $this->root . '/content/.analytics/' . date('Y-m') . '.json';
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, '{"broken');
        $log = $this->root . '/php-errors.log';
        $previous = ini_set('error_log', $log);

        try {
            $this->visit('photos');
            $month = Totals::summary(30)['totals']['month'];
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $this->assertSame(1, $month, 'the request from the database still counts');
        $this->assertSame('{"broken', file_get_contents($file));
        $this->assertStringContainsString('blog analytics: ', (string) file_get_contents($log));
    }

    public function testOwnAddressesAreNoBotsEither(): void
    {
        $this->visit('photos', ip: '198.51.100.7', ua: 'curl/8.7.1', browser: false);
        $this->visit('photos', ip: '203.0.113.9', ua: 'curl/8.7.1', browser: false);
        $this->kirby->impersonate('kirby', fn() => $this->kirby->site()->update(['ownIps' => '198.51.100.7']));

        $this->assertSame(['named' => 1, 'suspect' => 0, 'names' => [['name' => 'curl', 'hits' => 1]]], Totals::summary(30)['bots']);
    }

    public function testAboutShowsNothingBeforeTheFirstVisit(): void
    {
        $this->visit('2026-10-07-test-photo', ua: self::GOOGLEBOT, browser: false);

        $this->assertNull(Totals::stats());
    }

    public function testAboutCountsOnlyPersons(): void
    {
        $this->visit('2026-10-07-test-photo');
        $this->visit('2026-10-07-test-photo', ip: '203.0.113.5', ua: self::GOOGLEBOT, browser: false);

        $stats = Totals::stats();
        $this->assertNotNull($stats);
        $this->assertSame([1, 1, 1], [$stats['visits'], $stats['views'], $stats['pages']]);
        $this->assertSame('photos/test-photo', $stats['top'][0][0]->id());
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

    /**
     * Renders $path as a request. $_SERVER starts from its state before the
     * test, so no header of an earlier visit stays.
     *
     * @param array<string, string> $query
     * @param array<string, string> $headers further $_SERVER entries, such as HTTP_DNT
     */
    private function visit(
        string $path,
        string $ip = '203.0.113.1',
        string $ua = self::SAFARI,
        ?string $referer = null,
        string $method = 'GET',
        bool $session = false,
        array $query = [],
        bool $browser = true,
        array $headers = [],
    ): void {
        $_GET = $query;
        Campaign::strip();
        $_SERVER = $this->server;
        $_SERVER['REMOTE_ADDR'] = $ip;
        $_SERVER['HTTP_USER_AGENT'] = $ua;
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/2.0';
        // The URI as a browser sends it: a page with a slash at the end.
        $uri = '/' . $path . ($path === '' || str_contains(basename($path), '.') ? '' : '/');
        $_SERVER['REQUEST_URI'] = $uri . ($query === [] ? '' : '?' . http_build_query($query));
        if ($referer !== null) {
            $_SERVER['HTTP_REFERER'] = $referer;
        }
        // Every browser sends these two headers when it opens a page (see Kind).
        if ($browser) {
            $_SERVER['HTTP_SEC_FETCH_DEST'] = 'document';
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de-DE,de;q=0.9';
        }
        foreach ($headers as $key => $value) {
            $_SERVER[$key] = $value;
        }
        if ($session) {
            $_COOKIE['kirby_session'] = 'x';
        } else {
            unset($_COOKIE['kirby_session']);
        }
        $this->kirby->render($path, $method);
    }

    /** @return array<mixed>|null */
    private function today(): ?array
    {
        return Hits::days(date('Y-m-d'), date('Y-m-d'))[date('Y-m-d')] ?? null;
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $columns): array
    {
        $statement = (new Sqlite('sqlite:' . $this->database()))->query("SELECT $columns FROM hits ORDER BY rowid");
        $this->assertNotFalse($statement);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function database(): string
    {
        return $this->root . '/content/.analytics/' . date('Y-m') . '.sqlite';
    }
}
