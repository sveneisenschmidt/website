<?php

namespace Website\Tests\Integration;

use Kirby\Http\Response;
use Website\Analytics\Campaign;
use Website\Blog\Likes;
use Website\Models\PostPage;
use Website\Tests\Support\TestSite;

/**
 * The like button at the end of a post (Website\Blog\Likes): a form that
 * sends POST to /YYYY-MM-DD-slug/like, without JavaScript and without a
 * cookie.
 */
final class LikeTest extends IntegrationTestCase
{
    private const SAFARI = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15';

    /** @var array<mixed> */
    private array $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_GET = [];
        parent::tearDown();
    }

    public function testALikeLeadsBackToThePost(): void
    {
        $response = $this->request('/2026-10-07-test-photo/like', 'POST');

        $this->assertSame(303, $response->code());
        $this->assertSame('http://localhost/2026-10-07-test-photo/?liked=#like', (string) $response->header('Location'));
        $this->assertSame(1, Likes::count($this->current()));
    }

    public function testAnAddressLikesAPostOnce(): void
    {
        $this->request('/2026-10-07-test-photo/like', 'POST', ip: '203.0.113.1');
        $this->request('/2026-10-07-test-photo/like', 'POST', ip: '203.0.113.1');
        $this->request('/2026-10-07-test-photo/like', 'POST', ip: '203.0.113.2');

        $this->assertSame(2, Likes::count($this->current()));
    }

    /**
     * Kind decides who is a person, with the same rule as for the visitor
     * statistics: a program, or a browser name without the headers of a
     * browser, does not like.
     */
    public function testAProgramDoesNotLike(): void
    {
        $this->request('/2026-10-07-test-photo/like', 'POST', agent: 'curl/8.7.1');
        $this->request('/2026-10-07-test-photo/like', 'POST', browser: false);

        $this->assertSame(0, Likes::count($this->current()));
    }

    public function testOnlyAPublishedPostTakesLikes(): void
    {
        $draft = $this->post(slug: 'draft-photo', status: 'draft');
        $token = (string) parse_url((string) $this->kirby->impersonate('kirby', fn() => $draft->previewUrl()), PHP_URL_QUERY);

        $this->assertSame(404, $this->request('/2026-10-07-draft-photo/like', 'POST')->code());
        $this->assertSame(404, $this->request("/2026-10-07-draft-photo/like?$token", 'POST')->code(), 'the preview link opens the draft, but the draft takes no likes');
        $this->assertSame(404, $this->request('/2026-10-07-no-such-post/like', 'POST')->code());
        $this->assertSame(404, $this->request('/2026-10-07-test-photo/like')->code(), 'GET does not like');
        $this->assertFileDoesNotExist(Likes::file());
    }

    public function testTheFooterShowsTheButtonAndTheCount(): void
    {
        $html = (string) $this->request('/2026-10-07-test-photo/')->body();
        $this->assertStringContainsString('<form class="like" id="like" method="post" action="http://localhost/2026-10-07-test-photo/like"><button type="submit">Like</button></form>', $html, 'without likes no count');

        $this->request('/2026-10-07-test-photo/like', 'POST', ip: '203.0.113.1');
        $this->request('/2026-10-07-test-photo/like', 'POST', ip: '203.0.113.2');

        $html = (string) $this->request('/2026-10-07-test-photo/')->body();
        $this->assertStringContainsString('<button type="submit">Like</button> &middot; 2</form>', $html);
    }

    /**
     * The page cache keeps the HTML of a post until the next content change.
     * A like removes the post from the cache, so the next visitor sees the
     * new count.
     */
    public function testTheCachedPostShowsTheNewCount(): void
    {
        $this->request('/2026-10-07-test-photo/');
        $this->assertTrue($this->kirby->cache('pages')->exists('photos/test-photo.latest.html'));

        $this->request('/2026-10-07-test-photo/like', 'POST');

        $this->assertStringContainsString('Like</button> &middot; 1</form>', (string) $this->request('/2026-10-07-test-photo/')->body());
    }

    /**
     * After the like, the post says "Liked" to the address that liked it.
     * The cached page is the same for every visitor, so only the request
     * with ?liked, which Kirby never caches, checks the address.
     */
    public function testThePostSaysLikedOnlyAfterTheLike(): void
    {
        $this->request('/2026-10-07-test-photo/like', 'POST', ip: '203.0.113.1');

        $liked = (string) $this->request('/2026-10-07-test-photo/?liked', ip: '203.0.113.1')->body();
        $this->assertStringContainsString('<form class="like" id="like" method="post" action="http://localhost/2026-10-07-test-photo/like"><strong>Liked</strong> &middot; 1</form>', $liked);

        $other = (string) $this->request('/2026-10-07-test-photo/?liked', ip: '203.0.113.2')->body();
        $this->assertStringContainsString('<button type="submit">Like</button> &middot; 1</form>', $other, 'another address has not liked');

        $plain = (string) $this->request('/2026-10-07-test-photo/', ip: '203.0.113.1')->body();
        $this->assertStringContainsString('<button type="submit">Like</button> &middot; 1</form>', $plain, 'without ?liked the page is the same for everyone');
    }

    /** A like belongs to the page's UUID, so it stays after a change of slug or date. */
    public function testLikesStayAfterAChangeOfSlug(): void
    {
        $this->request('/2026-10-07-test-photo/like', 'POST');

        $kirby = TestSite::app($this->root, $this->options);
        $moved = $kirby->impersonate('kirby', fn() => $kirby->page('photos/test-photo')->changeSlug('moved-photo'));

        $this->assertSame(1, Likes::count($moved));
    }

    /**
     * Likes from Pop, the counter before Kirby, have no fingerprint. They
     * count, and they do not stop a new like from an address.
     */
    public function testLikesWithoutFingerprintCount(): void
    {
        $db = Likes::connect(Likes::file());
        $insert = $db->prepare('INSERT INTO likes (page, ip_hash, time) VALUES (:page, NULL, :time)');
        $insert->execute(['page' => $this->current()->uuid()->id(), 'time' => '2026-03-01T10:00:00.000+01:00']);
        $insert->execute(['page' => $this->current()->uuid()->id(), 'time' => '2026-03-02T10:00:00.000+01:00']);

        $this->request('/2026-10-07-test-photo/like', 'POST');

        $this->assertSame(3, Likes::count($this->current()));
    }

    /**
     * The Panel view "Besucher" shows the likes: the total, the last 30
     * days, the posts by likes and every like, newest first. A post with
     * the same number of likes as another comes first when its newest like
     * is newer. A deleted post keeps its likes, as in the visitor statistics.
     * The 30 days are today and the 29 days before it.
     */
    public function testThePanelSummary(): void
    {
        $second = $this->post(slug: 'second-photo', title: 'Second Photo');
        $now = new \DateTimeImmutable();
        $time = fn(string $ago) => $now->modify($ago)->format('Y-m-d\TH:i:s.vP');
        $rows = [
            [$this->post->uuid()->id(), $time('-40 days')],
            [$this->post->uuid()->id(), $time('-1 day')],
            [$second->uuid()->id(), $time('-50 days')],
            [$second->uuid()->id(), $time('-29 days')],
            ['gone', $time('-30 days')],
            ['gone', $time('-61 days')],
            ['gone', $time('-62 days')],
        ];
        $insert = Likes::connect(Likes::file())->prepare('INSERT INTO likes (page, ip_hash, time) VALUES (:page, NULL, :time)');
        foreach ($rows as [$page, $at]) {
            $insert->execute(['page' => $page, 'time' => $at]);
        }

        $summary = Likes::summary(30);

        $this->assertSame(7, $summary['total']);
        $this->assertSame(2, $summary['month']);
        $this->assertSame([
            ['title' => 'gone (gelöscht)', 'url' => null, 'likes' => 3],
            ['title' => 'Test Photo', 'url' => 'http://localhost/2026-10-07-test-photo/', 'likes' => 2],
            ['title' => 'Second Photo', 'url' => 'http://localhost/2026-10-07-second-photo/', 'likes' => 2],
        ], $summary['posts']);
        $this->assertSame(['Test Photo', 'Second Photo', 'gone (gelöscht)', 'Test Photo', 'Second Photo', 'gone (gelöscht)', 'gone (gelöscht)'], array_column($summary['likes'], 'title'));
        $this->assertSame(substr($time('-1 day'), 0, 10), $summary['likes'][0]['date']);
    }

    public function testThePanelSummaryWithoutLikes(): void
    {
        $this->assertSame(['total' => 0, 'month' => 0, 'posts' => [], 'likes' => []], Likes::summary(30));
        $this->assertFileDoesNotExist(Likes::file());
    }

    /** The Panel view "Besucher" gets the likes of the last 30 days as its prop `likes`. */
    public function testThePanelViewGetsTheLikes(): void
    {
        Likes::connect(Likes::file())->prepare('INSERT INTO likes (page, ip_hash, time) VALUES (:page, NULL, :time)')
            ->execute(['page' => $this->post->uuid()->id(), 'time' => (new \DateTimeImmutable('-10 days'))->format('Y-m-d\TH:i:s.vP')]);

        $area = $this->kirby->extensions('areas')['besucher'][0]();
        $props = $area['views'][0]['action']()['props'];

        $this->assertSame(1, $props['likes']['month']);
        $this->assertSame(Likes::summary(30), $props['likes']);
    }

    /**
     * A broken file breaks no post, as in the visitor statistics. The post
     * shows the button without a count. It stays out of the page cache, so
     * the first request after a repair shows the count.
     */
    public function testABrokenFileKeepsThePostsUp(): void
    {
        file_put_contents(Likes::file(), 'not a database');

        [$response, $log] = $this->logged(fn() => $this->request('/2026-10-07-test-photo/'));

        $this->assertSame(200, $response->code());
        $this->assertStringContainsString('<button type="submit">Like</button></form>', (string) $response->body());
        $this->assertFalse($this->kirby->cache('pages')->exists('photos/test-photo.latest.html'));
        $this->assertStringContainsString('blog likes: ', $log);
    }

    /** With a broken file, the Panel view still opens, as with the visitor statistics. */
    public function testABrokenFileGivesAnEmptySummary(): void
    {
        file_put_contents(Likes::file(), 'not a database');

        [$summary, $log] = $this->logged(fn() => Likes::summary(30));

        $this->assertSame(['total' => 0, 'month' => 0, 'posts' => [], 'likes' => []], $summary);
        $this->assertStringContainsString('blog likes: ', $log);
    }

    /** With a broken file, a like still leads back to the post. */
    public function testABrokenFileStillLeadsBackAfterALike(): void
    {
        file_put_contents(Likes::file(), 'not a database');

        [$like, $log] = $this->logged(fn() => $this->request('/2026-10-07-test-photo/like', 'POST'));
        [$post] = $this->logged(fn() => $this->request('/2026-10-07-test-photo/?liked'));

        $this->assertSame(303, $like->code());
        $this->assertStringContainsString('blog likes: ', $log);
        $this->assertSame(200, $post->code());
        $this->assertStringContainsString('<button type="submit">Like</button></form>', (string) $post->body());
    }

    /**
     * Runs $action with the PHP error log in a file and returns the result
     * and the log. phpunit.xml fails a test on any output.
     *
     * @template T
     * @param \Closure(): T $action
     * @return array{T, string}
     */
    private function logged(\Closure $action): array
    {
        $log = $this->root . '/php-errors.log';
        $previous = ini_set('error_log', $log);
        try {
            $result = $action();
        } finally {
            ini_set('error_log', (string) $previous);
        }
        return [$result, is_file($log) ? (string) file_get_contents($log) : ''];
    }

    /** The post as the current Kirby reads it. */
    private function current(): PostPage
    {
        $post = TestSite::app($this->root, $this->options)->page('photos/test-photo');
        $this->assertInstanceOf(PostPage::class, $post);
        return $post;
    }

    /**
     * Sends one request to a new Kirby, because Kirby reads the request only
     * when it starts. The request comes from a browser, with the two headers
     * that Kind looks for, unless $browser is false.
     */
    private function request(string $uri, string $method = 'GET', string $ip = '203.0.113.1', string $agent = self::SAFARI, bool $browser = true): Response
    {
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $_GET);
        Campaign::strip();
        $_SERVER = $this->server;
        $_SERVER['REMOTE_ADDR'] = $ip;
        $_SERVER['HTTP_USER_AGENT'] = $agent;
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['REQUEST_METHOD'] = $method;
        if ($browser) {
            $_SERVER['HTTP_SEC_FETCH_DEST'] = 'document';
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de-DE,de;q=0.9';
        }
        // Kirby reads the method of a request from the Kirby that ran before,
        // so the method goes to the new Kirby directly.
        $kirby = TestSite::app($this->root, $this->options, [
            'server' => ['REQUEST_URI' => $uri, 'REQUEST_METHOD' => $method],
            'request' => ['method' => $method, 'query' => $_GET, 'url' => 'http://localhost' . $uri],
        ]);
        return $kirby->render() ?? $this->fail("No response for $method $uri");
    }
}
