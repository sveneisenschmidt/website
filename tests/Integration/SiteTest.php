<?php

namespace Website\Tests\Integration;

/**
 * The texts about the site and its author come from the content: the tab
 * "Angaben" of the site and the sidebar of About. An empty field leaves its
 * tag or key out of the page.
 */
final class SiteTest extends IntegrationTestCase
{
    public function testTheContentFillsHeaderMetaTagsMailLinkAndJsonLd(): void
    {
        $this->kirby->impersonate('kirby', function () {
            $site = $this->kirby->site()->update(['heading' => 'The Author', 'description' => 'A personal website.', 'keywords' => 'photos, notes']);
            $site->createChild(['slug' => 'home', 'template' => 'home', 'draft' => false, 'content' => ['title' => 'Home']]);
            $site->createChild(['slug' => 'about', 'template' => 'about', 'draft' => false, 'content' => [
                'title' => 'About',
                'author' => 'The Author',
                'email' => 'author@website.test',
                'profiles' => "- url: https://example.com/a\n- url: https://example.com/b\n",
            ]]);
        });
        $post = $this->kirby->page('photos/test-photo')->render();
        $home = $this->kirby->site()->homePage()->render();

        $this->assertStringContainsString('<p class="masthead"><a href="/">The Author</a></p>', $post);
        $this->assertStringContainsString('<meta name="author" content="The Author">', $post);
        $this->assertStringContainsString('<meta name="keywords" content="photos, notes">', $post);
        $this->assertStringContainsString('<meta property="article:author" content="The Author">', $post);
        $this->assertStringContainsString('<a href="mailto:author@website.test?subject=Re%3A%20Test%20Photo">Leave me a message</a>', $post);
        $this->assertStringContainsString('<meta name="description" content="A personal website.">', $home);
        $this->assertStringContainsString('"sameAs": [', $home);
        $this->assertStringContainsString('"https://example.com/b"', $home);
    }

    public function testEmptyFieldsLeaveTheirTagsOut(): void
    {
        $html = $this->post->render();

        $this->assertStringNotContainsString('<meta name="author"', $html);
        $this->assertStringNotContainsString('<meta name="keywords"', $html);
        $this->assertStringNotContainsString('article:author', $html);
        $this->assertStringNotContainsString('mailto:', $html);
    }
}
