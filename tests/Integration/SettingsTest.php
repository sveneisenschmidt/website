<?php

namespace Website\Tests\Integration;

use Kirby\Api\Controller\Changes;
use Website\Tests\Support\TestSite;

/**
 * The settings of the modules (Website\Blog\Settings). Each module edits
 * its fields of the site on a page of its own menu item, such as
 * Besucher › Einstellungen. The view "Website" has no tab for them. The
 * values stay in content/site.txt.
 */
final class SettingsTest extends IntegrationTestCase
{
    private const VALUES = [
        'ownIps' => '198.51.100.7',
        'assistantPrompt' => 'PROMPT-CANARY-SETTINGS-77ab',
        'assistantModel' => 'claude-sonnet-5-5',
    ];

    protected function tearDown(): void
    {
        // Kirby writes the session at PHP shutdown, after parent::tearDown()
        // has deleted its folder. So the test destroys the session first.
        $this->kirby->session()->destroy();
        parent::tearDown();
    }

    public function testWebsiteHasOnlyThePostsAndTheDetails(): void
    {
        $this->assertSame(['beitraege', 'angaben'], array_column($this->kirby->site()->blueprint()->tabs(), 'name'));
        foreach (array_keys(self::VALUES) as $field) {
            $this->assertNull($this->kirby->site()->blueprint()->field(strtolower($field)), $field);
        }
    }

    /** The page Besucher › Einstellungen has the field of the own IP addresses with its value. */
    public function testTheVisitorsHaveTheirSettingsPage(): void
    {
        $this->store();

        $area = $this->kirby->extensions('areas')['besucher'][0]();
        $view = array_values(array_filter($area['views'], fn(array $view) => $view['pattern'] === 'besucher/einstellungen'))[0]['action']();

        $this->assertSame('k-blog-analytics-settings-view', $view['component']);
        $this->assertSame(['ownips'], array_keys($view['props']['fields']));
        $this->assertSame('textarea', $view['props']['fields']['ownips']['type']);
        $this->assertStringContainsString('Deine Adresse gerade:', $view['props']['fields']['ownips']['help']);
        $this->assertSame(['ownips' => '198.51.100.7'], $view['props']['value']);
    }

    /**
     * "Speichern" on a settings page sends its fields to Kirby's API (PATCH
     * site), and the other fields of the site keep their values. The test
     * pins this behaviour of Kirby for later updates.
     */
    public function testASettingsPageSavesThroughTheApi(): void
    {
        $this->store();
        $this->api();

        $this->kirby->api()->call('site', 'PATCH', ['body' => ['ownips' => "198.51.100.7\n203.0.113.9"]]);

        $this->assertSame("198.51.100.7\n203.0.113.9", $this->kirby->site()->content()->get('ownIps')->value());
        $this->assertSame(self::VALUES['assistantPrompt'], $this->kirby->site()->content()->get('assistantPrompt')->value());
    }

    /**
     * The view "Website" saves a draft while the user types and publishes it
     * with "Speichern". Both keep the settings, although the site blueprint
     * does not name them. The test pins this behaviour of Kirby for later
     * updates.
     */
    public function testASaveInTheViewWebsiteKeepsTheSettings(): void
    {
        $this->store();
        $this->api();

        $this->websiteSave(['heading' => 'New heading']);

        $this->api();
        $content = $this->kirby->site()->content();
        $this->assertSame('New heading', $content->get('heading')->value());
        foreach (self::VALUES as $field => $value) {
            $this->assertSame($value, $content->get($field)->value(), $field);
        }
    }

    /**
     * A draft of the view "Website" holds the values from the moment the
     * user began to type. A settings page writes its values into that draft
     * too, so that the later save of the draft keeps them. Without a draft,
     * the settings page leaves none behind.
     */
    public function testASettingsPageUpdatesAnOpenDraftOfTheSite(): void
    {
        $this->store();
        $this->api();
        $site = $this->kirby->site();
        Changes::save($site, ['heading' => 'Draft heading'] + $site->panel()->versions()['changes']);

        $this->settingsSave(['ownips' => '203.0.113.9']);
        // The user goes back to the view "Website" and saves the draft.
        $this->api();
        Changes::publish($this->kirby->site(), $this->kirby->site()->panel()->versions()['changes']);

        $this->api();
        $this->assertSame('Draft heading', $this->kirby->site()->content()->get('heading')->value());
        $this->assertSame('203.0.113.9', $this->kirby->site()->content()->get('ownIps')->value());

        $this->settingsSave(['ownips' => '203.0.113.10']);
        $this->api();
        $this->assertSame('203.0.113.10', $this->kirby->site()->content()->get('ownIps')->value());
        $this->assertFalse($this->kirby->site()->version('changes')->exists('current'));
    }

    /**
     * The two requests of "Speichern" on a settings page (index.js of
     * analytics and assistant).
     *
     * @param array<string, mixed> $values
     */
    private function settingsSave(array $values): void
    {
        $this->kirby->api()->call('site', 'PATCH', ['body' => $values]);
        Changes::save($this->kirby->site(), $values);
    }

    /**
     * The save of the view "Website": the Panel sends all values of the form
     * (with the fields that the blueprint does not name) to changes/save,
     * then to changes/publish. The routes of changes read the request, so
     * the test calls Kirby's controller directly.
     *
     * @param array<string, mixed> $input
     */
    private function websiteSave(array $input): void
    {
        $site = $this->kirby->site();
        $values = $input + $site->panel()->versions()['changes'];
        Changes::save($site, $values);
        Changes::publish($site, $values);
    }

    /**
     * A new Kirby, as for the next request, whose API takes the impersonated
     * user, as AssistantTestCase does.
     */
    private function api(): void
    {
        $this->options['api'] = ['allowImpersonation' => true];
        $this->kirby = TestSite::app($this->root, $this->options);
        $this->kirby->impersonate('kirby');
    }

    private function store(): void
    {
        $this->kirby->impersonate('kirby', fn() => $this->kirby->site()->update(self::VALUES));
    }
}
