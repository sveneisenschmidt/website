<?php

namespace Website\Tests\Integration;

use Kirby\Exception\PermissionException;
use Website\Tests\Support\TestSite;

/**
 * Only accounts with the role admin use the assistant (spec, "Aufbau").
 */
final class AssistantAccessTest extends IntegrationTestCase
{
    protected array $props = ['roles' => [['name' => 'editor', 'title' => 'Editor']]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->kirby->impersonate('kirby', fn () => $this->kirby->users()->create([
            'id' => 'editor',
            'email' => 'editor@website.test',
            'role' => 'editor',
            'password' => 'editor-password-1',
        ]));
    }

    public function testOnlyAdminsMayUseTheAssistant(): void
    {
        $this->assertFalse(blog_assistant_allowed(), 'Nobody is logged in.');
        $this->kirby->impersonate('kirby');
        $this->assertTrue(blog_assistant_allowed());
        $this->kirby->impersonate('nobody');
        $this->assertFalse(blog_assistant_allowed());
        $this->kirby->impersonate('editor');
        $this->assertFalse(blog_assistant_allowed());
    }

    public function testTheGuardStopsOtherRoles(): void
    {
        $this->kirby->impersonate('editor');
        $this->expectException(PermissionException::class);
        blog_assistant_guard();
    }

    public function testTheTabAssistentIsForAdminsOnly(): void
    {
        $this->kirby->impersonate('kirby');
        $this->assertSame(['beitraege', 'assistent'], array_column($this->kirby->site()->blueprint()->tabs(), 'name'));

        $this->kirby = TestSite::app($this->root, $this->options, $this->props);
        $this->kirby->impersonate('editor');
        $this->assertSame(['beitraege'], array_column($this->kirby->site()->blueprint()->tabs(), 'name'));
    }
}
