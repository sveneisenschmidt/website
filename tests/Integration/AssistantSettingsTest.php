<?php

namespace Website\Tests\Integration;

use Website\Assistant\Claude;

/**
 * The page Assistent › Einstellungen. The prompt field has no default, no
 * template and no help text, so the prompt is stored only in
 * content/site.txt, which is not in git. A closure with a scripted reply
 * takes the place of the Models API, so no test calls the network.
 */
final class AssistantSettingsTest extends IntegrationTestCase
{
    /**
     * The scripted reply of the Models API: [HTTP status, decoded response].
     *
     * @var array{int, array<mixed>}
     */
    private array $catalog = [200, ['data' => []]];

    protected function setUp(): void
    {
        $this->options = ['blog.assistant.models' => fn() => $this->catalog];
        parent::setUp();
    }

    public function testThePromptFieldBringsNoText(): void
    {
        $props = $this->props();
        $field = $props['fields']['assistantprompt'];

        $this->assertSame('textarea', $field['type']);
        foreach (['default', 'template', 'help', 'placeholder', 'value'] as $key) {
            $this->assertEmpty($field[$key] ?? null, $key);
        }
        $this->assertSame('', $props['value']['assistantprompt']);
        $this->assertFalse($props['hasKey'], 'the test site has no env.php');
    }

    /** A site that never saved the settings shows the values the chat uses. */
    public function testWithoutSavedValuesThePageShowsTheDefaults(): void
    {
        $props = $this->props();

        $this->assertSame(Claude::DEFAULT_MODEL, $props['value']['assistantmodel']);
        $this->assertSame(Claude::DEFAULT_EFFORT, $props['value']['assistanteffort']);
        $this->assertSame(Claude::DEFAULT_EFFORT, $props['fields']['assistanteffort']['default']);
    }

    /** The page shows the stored prompt, model and effort, and no other field of the site. */
    public function testThePageHasTheStoredValues(): void
    {
        $this->kirby->impersonate('kirby', fn() => $this->kirby->site()->update([
            'assistantPrompt' => 'PROMPT-CANARY-SETTINGS-5e1d',
            'assistantModel' => 'claude-haiku-5-5',
            'assistantEffort' => 'high',
        ]));

        $value = $this->props()['value'];
        ksort($value);
        $this->assertSame(
            ['assistanteffort' => 'high', 'assistantmodel' => 'claude-haiku-5-5', 'assistantprompt' => 'PROMPT-CANARY-SETTINGS-5e1d'],
            $value,
        );
    }

    /** The effort field has a label for every level that `Claude::effort()` accepts. */
    public function testTheEffortFieldListsTheLevelsOfTheApi(): void
    {
        $this->assertSame(Claude::EFFORTS, array_column($this->props()['fields']['assistanteffort']['options'], 'value'));
    }

    /**
     * The list holds the models that take the request of the chat: adaptive
     * thinking with an effort level. Each one has the levels it supports.
     */
    public function testTheModelsComeFromTheApi(): void
    {
        $this->catalog = [200, ['data' => [
            $this->model('claude-new-7', 'Claude New 7', ['low', 'medium', 'high', 'xhigh', 'max']),
            $this->model('claude-opus-5-5', 'Claude Opus 5.5', ['low', 'medium', 'high']),
            $this->model('claude-no-effort', 'No Effort', []),
            $this->model('claude-no-adaptive', 'No Adaptive', ['low', 'medium', 'high'], adaptive: false),
        ]]];

        $this->assertSame([
            ['id' => 'claude-new-7', 'name' => 'Claude New 7', 'efforts' => ['low', 'medium', 'high', 'xhigh', 'max']],
            ['id' => 'claude-opus-5-5', 'name' => 'Claude Opus 5.5', 'efforts' => ['low', 'medium', 'high']],
        ], $this->props()['models']);
    }

    /** A saved model stays selectable when the API no longer lists it or does not answer. */
    public function testTheSavedModelIsAlwaysInTheList(): void
    {
        $this->kirby->impersonate('kirby', fn() => $this->kirby->site()->update(['assistantModel' => 'claude-retired-1']));
        $this->catalog = [200, ['data' => [$this->model('claude-opus-5-5', 'Claude Opus 5.5', ['low', 'medium', 'high'])]]];

        $this->assertSame(['claude-retired-1', 'claude-opus-5-5'], array_column($this->props()['models'], 'id'));

        $this->expectErrorLog();
        $this->catalog = [500, []];

        $this->assertSame(
            [['id' => 'claude-retired-1', 'name' => 'claude-retired-1', 'efforts' => Claude::EFFORTS]],
            $this->props()['models'],
        );
    }

    /**
     * One entry of the Models API. The API marks every effort level and
     * every thinking type as supported or not.
     *
     * @param list<string> $efforts
     * @return array<string, mixed>
     */
    private function model(string $id, string $name, array $efforts, bool $adaptive = true): array
    {
        $levels = [];
        foreach (Claude::EFFORTS as $level) {
            $levels[$level] = ['supported' => in_array($level, $efforts, true)];
        }
        return [
            'type' => 'model',
            'id' => $id,
            'display_name' => $name,
            'capabilities' => [
                'effort' => ['supported' => $efforts !== []] + $levels,
                'thinking' => ['supported' => true, 'types' => ['adaptive' => ['supported' => $adaptive]]],
            ],
        ];
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
