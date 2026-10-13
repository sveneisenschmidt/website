<?php

namespace Website\Tests\Integration;

use Kirby\Cms\Response;
use Website\Blog\Redirects;
use Website\Tests\Support\TestSite;

/**
 * The redirects in content/.redirects.json answer before any page
 * (`Website\Blog\Router`).
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

    public function testTheRedirectsAreStoredInContent(): void
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

    public function testATargetOnAnotherHostIsIgnored(): void
    {
        // The base URL in front of ".example.com" would make
        // http://localhost.example.com.
        Redirects::write([['from' => '/away/', 'to' => '.example.com'], ['from' => '/twice/', 'to' => '//example.com']]);

        $this->assertNull(Redirects::target('away'));
        $this->assertNull(Redirects::target('twice'));
    }

    public function testABrokenFileKeepsThePagesUp(): void
    {
        file_put_contents(Redirects::file(), '[{"from": ');
        // phpunit.xml fails a test on any output, so the log goes to a file.
        $log = $this->root . '/php-errors.log';
        $previous = ini_set('error_log', $log);

        try {
            $target = Redirects::target('posts/daily-photo-test');
            $response = TestSite::app($this->root, $this->options, ['server' => ['REQUEST_URI' => '/2026-10-07-test-photo/', 'REQUEST_METHOD' => 'GET']])->render();
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $this->assertNull($target);
        $this->assertSame(200, $response?->code());
        $this->assertStringContainsString('blog redirects: ', (string) file_get_contents($log));
        $this->assertSame('[{"from": ', file_get_contents(Redirects::file()), 'nothing overwrites the broken file');
    }

    /**
     * HEAD answers like GET, so a link checker sees the redirect and the
     * post, and no 404.
     */
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
