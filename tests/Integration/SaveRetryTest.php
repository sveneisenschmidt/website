<?php

namespace Website\Tests\Integration;

use Kirby\Cms\App;
use Kirby\Panel\Plugins;
use Website\Tests\Support\TestSite;

/**
 * The plugin save-retry (plugins/save-retry) sends a failed draft save of
 * the Panel once more. That part is JavaScript and runs in the browser, so
 * this test checks only that the Panel gets the script.
 */
final class SaveRetryTest extends IntegrationTestCase
{
    public function testThePanelLoadsTheScript(): void
    {
        $script = trim((string) file_get_contents(TestSite::SITE . '/plugins/save-retry/index.js'));

        $this->assertNotNull(App::plugin('website/save-retry'));
        $this->assertStringContainsString($script, (new Plugins())->read('js'));
    }
}
