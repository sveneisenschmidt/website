<?php

namespace Website\Tests\Integration;

use Kirby\Exception\AuthException;
use Website\Assistant\Panel;

/**
 * Every user who is logged in to the Panel can use the assistant. Without a
 * login, its Panel area leads to the login page and its API routes fail.
 */
final class AssistantAccessTest extends AssistantTestCase
{
    public function testTheSiteHasTheTabsPostsAndAssistant(): void
    {
        $this->assertSame(['beitraege', 'assistent'], array_column($this->kirby->site()->blueprint()->tabs(), 'name'));
    }

    public function testTheLoggedInUserHasAreaAndButton(): void
    {
        $this->assertArrayHasKey('views', $this->kirby->load()->areas()['assistent']);
        $this->assertSame('assistent?page=photos%2Ftest-photo', Panel::button($this->post)->link);
    }

    public function testWithoutALoginTheApiRefuses(): void
    {
        $this->kirby->impersonate();

        try {
            $this->kirby->api()->call('blog-assistant/send', 'POST', ['body' => ['text' => 'Hallo']]);
            $this->fail('No error');
        } catch (AuthException) {
        }
        $this->assertSame([], $this->requests);
    }

    public function testWithoutALoginTheAreaLeadsToTheLogin(): void
    {
        // Kirby shows its installation page while no account exists. With one
        // account, the Panel leads to the login page.
        $this->kirby->users()->create(['email' => 'author@website.test', 'role' => 'admin', 'password' => 'test-password-123']);
        $this->kirby->impersonate();

        $response = $this->kirby->render('panel/assistent');

        $this->assertSame(302, $response->code());
        $this->assertSame('0; url=http://localhost/panel/login', $response->header('Refresh'));
    }
}
