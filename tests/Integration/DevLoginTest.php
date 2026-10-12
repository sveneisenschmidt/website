<?php

namespace Website\Tests\Integration;

use Kirby\Cms\App;
use Kirby\Cms\User;
use Kirby\Exception\PermissionException;
use PHPUnit\Framework\TestCase;
use Website\Tests\Support\TestSite;

/**
 * The dev login from bin/seed.php uses an address under .test. It must
 * only work with debug on, so a local account that reaches the server
 * (debug off there) cannot be used to log in.
 */
final class DevLoginTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TestSite::create();
    }

    protected function tearDown(): void
    {
        TestSite::remove($this->root);
    }

    public function testDevLoginIsRefusedWithoutDebug(): void
    {
        $user = $this->devUser(TestSite::app($this->root, ['debug' => false]));

        $this->expectException(PermissionException::class);
        $user->login('dev-panel-login');
    }

    public function testDevLoginWorksWithDebug(): void
    {
        $user = $this->devUser(TestSite::app($this->root, ['debug' => true]));

        $this->assertTrue($user->login('dev-panel-login'));
        // Ends the session before tearDown removes its folder.
        $user->logout();
    }

    private function devUser(App $kirby): User
    {
        return $kirby->impersonate('kirby', fn () => $kirby->users()->create([
            'email' => 'dev@sven.eisenschmidt.test',
            'password' => 'dev-panel-login',
            'role' => 'admin',
        ]));
    }
}
