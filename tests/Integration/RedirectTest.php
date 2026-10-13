<?php

namespace Website\Tests\Integration;

use Kirby\Cms\Response;
use Website\Blog\Redirects;
use Website\Tests\Support\TestSite;

/**
 * The redirects in content/.redirects.json answer before any page, as
 * RedirectMatch did on Apache (Website\Blog\Router).
 */
final class RedirectTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Redirects::write([
            ['from' => '/posts/daily-photo-test/', 'to' => '/2026-10-07-test-photo/', 'pop' => 'keep', 'rss' => 'keep'],
            ['from' => '/photos/', 'to' => '/archive/photos/'],
            ['from' => '/page/2/', 'to' => '/'],
            ['from' => '/projects/', 'to' => '/about/#things-i-built'],
        ]);
    }

    public function testTheStoreIsContent(): void
    {
        $this->assertFileExists($this->root . '/content/.redirects.json');
        $this->assertSame('/2026-10-07-test-photo/', Redirects::target('posts/daily-photo-test'));
        $this->assertNull(Redirects::target('posts/other'));
    }

    public function testRedirectsComeFirst(): void
    {
        $this->assertRedirect('/posts/daily-photo-test', 'http://localhost/2026-10-07-test-photo/');
        $this->assertRedirect('/posts/daily-photo-test/', 'http://localhost/2026-10-07-test-photo/');
        $this->assertRedirect('/photos/', 'http://localhost/archive/photos/', 'before the section page photos');
        $this->assertRedirect('/page/2/', 'http://localhost/');
        $this->assertRedirect('/projects/', 'http://localhost/about/#things-i-built');
    }

    /** HEAD answers like GET: a link checker sees the post and the redirect, no 404. */
    public function testHeadAnswersLikeGet(): void
    {
        $this->assertRedirect('/posts/daily-photo-test/', 'http://localhost/2026-10-07-test-photo/', 'HEAD', 'HEAD');
        $response = TestSite::app($this->root, $this->options, ['server' => ['REQUEST_URI' => '/2026-10-07-test-photo/', 'REQUEST_METHOD' => 'HEAD']])->render();
        $this->assertSame(200, $response?->code());
    }

    private function assertRedirect(string $uri, string $location, string $message = '', string $method = 'GET'): void
    {
        $response = TestSite::app($this->root, $this->options, ['server' => ['REQUEST_URI' => $uri, 'REQUEST_METHOD' => $method]])->render();
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(301, $response->code(), "$uri $message");
        $this->assertSame($location, (string) $response->header('Location'), "$uri $message");
    }
}
