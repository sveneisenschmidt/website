<?php

namespace Website\Tests\Integration;

/**
 * The Panel tab Website › Assistent. The prompt field has no default, no
 * template and no help text, so the prompt is stored only in
 * content/site.txt, which is not in git.
 */
final class AssistantSettingsTest extends IntegrationTestCase
{
    public function testThePromptFieldBringsNoText(): void
    {
        $this->kirby->impersonate('kirby');
        $field = $this->kirby->site()->blueprint()->field('assistantprompt');

        $this->assertSame('textarea', $field['type']);
        foreach (['default', 'template', 'help', 'placeholder', 'value'] as $key) {
            $this->assertEmpty($field[$key] ?? null, $key);
        }
        $this->assertTrue($this->kirby->site()->content()->get('assistantPrompt')->isEmpty());
        $this->assertSame('claude-opus-5-5', $this->kirby->site()->blueprint()->field('assistantmodel')['default']);
    }
}
