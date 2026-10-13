<?php

namespace Website\Tests\Integration;

use Kirby\Http\Response;
use Website\Tests\Support\TestSite;

/**
 * Requests as a browser sends them, the slash at the end included
 * (config/routes.php, Website\Blog\Router).
 */
final class RoutingTest extends IntegrationTestCase
{
    /** @var array<mixed> */
    private array $get;

    protected function setUp(): void
    {
        parent::setUp();
        $this->get = $_GET;
        $this->kirby->impersonate('kirby', function () {
            $site = $this->kirby->site();
            $site->createChild(['slug' => 'about', 'template' => 'about', 'draft' => false, 'content' => ['title' => 'About']]);
            $site->createChild(['slug' => 'search', 'template' => 'search', 'draft' => false, 'content' => ['title' => 'Search']]);
        });
    }

    protected function tearDown(): void
    {
        $_GET = $this->get;
        parent::tearDown();
    }

    public function testAPostAnswersAtItsUrl(): void
    {
        $response = $this->get('/2026-10-07-test-photo/');

        $this->assertSame(200, $response->code());
        $this->assertStringContainsString('<h1>Test Photo</h1>', $response->body());
    }

    public function testEveryOtherAddressOfAPageLeadsToItsUrl(): void
    {
        $this->assertRedirect('/2026-10-07-test-photo', 'http://localhost/2026-10-07-test-photo/');
        $this->assertRedirect('/photos/test-photo', 'http://localhost/2026-10-07-test-photo/');
        $this->assertRedirect('/about', 'http://localhost/about/');
        $this->assertSame(200, $this->get('/about/')->code());

        $_GET = ['source' => 'rss'];
        $this->assertRedirect('/2026-10-07-test-photo?source=rss', 'http://localhost/2026-10-07-test-photo/?source=rss');
        $this->assertSame(200, $this->get('/2026-10-07-test-photo/?source=rss')->code());
    }

    public function testAnOriginalImageAtItsOldUrlNextToThePost(): void
    {
        $file = $this->upload($this->jpeg(8, 8), 'a.jpg');

        $this->assertRedirect('/2026-10-07-test-photo/a.jpg', $file->url());
        $this->assertSame(404, $this->get('/2026-10-07-test-photo/b.jpg')->code());
    }

    public function testKirbyAnswersTheRest(): void
    {
        $this->post('notes', 'note', 'a-draft', 'A Draft', 'draft');

        $this->assertSame(404, $this->get('/2026-10-08-test-photo/')->code(), 'wrong date');
        $this->assertSame(404, $this->get('/2026-10-07-a-draft/')->code(), 'a draft without its token');
        $this->assertSame(404, $this->get('/nothing-here/')->code());
        $this->assertSame('application/json', $this->get('/search.json')->type());
        $this->assertSame('text/plain', $this->get('/robots.txt')->type());
    }

    /**
     * Sends one request to a new Kirby, because Kirby reads the request only
     * when it starts.
     */
    private function get(string $uri): Response
    {
        return TestSite::app($this->root, $this->options, ['server' => ['REQUEST_URI' => $uri]])->render() ?? $this->fail("No response for $uri");
    }

    private function assertRedirect(string $uri, string $location, string $message = ''): void
    {
        $response = $this->get($uri);
        $this->assertSame(301, $response->code(), "$uri $message");
        $this->assertSame($location, (string) $response->header('Location'), "$uri $message");
    }
}
