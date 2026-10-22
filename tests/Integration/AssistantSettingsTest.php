<?php

namespace Website\Tests\Integration;

/**
 * The page Assistent › Einstellungen. The prompt field has no default, no
 * template and no help text, so the prompt is stored only in
 * content/site.txt, which is not in git.
 */
final class AssistantSettingsTest extends IntegrationTestCase
{
    public function testThePromptFieldBringsNoText(): void
    {
        $props = $this->props();
        $field = $props['fields']['assistantprompt'];

        $this->assertSame('textarea', $field['type']);
        foreach (['default', 'template', 'help', 'placeholder', 'value'] as $key) {
            $this->assertEmpty($field[$key] ?? null, $key);
        }
        $this->assertSame('', $props['value']['assistantprompt']);
        $this->assertSame('claude-opus-5-5', $props['fields']['assistantmodel']['default']);
        $this->assertFalse($props['hasKey'], 'the test site has no env.php');
    }

    /** The page shows the stored prompt and model, and no other field of the site. */
    public function testThePageHasTheStoredValues(): void
    {
        $this->kirby->impersonate('kirby', fn() => $this->kirby->site()->update([
            'assistantPrompt' => 'PROMPT-CANARY-SETTINGS-5e1d',
            'assistantModel' => 'claude-haiku-5-5',
        ]));

        $this->assertSame(
            ['assistantprompt' => 'PROMPT-CANARY-SETTINGS-5e1d', 'assistantmodel' => 'claude-haiku-5-5'],
            $this->props()['value'],
        );
    }

    /** @return array<string, mixed> */
    private function props(): array
    {
        $area = $this->kirby->extensions('areas')['assistent'][0]();
        $view = array_values(array_filter($area['views'], fn(array $view) => $view['pattern'] === 'assistent/einstellungen'))[0];
        $result = $view['action']();
        $this->assertSame('k-blog-assistant-settings-view', $result['component']);
        return $result['props'];
    }
}
