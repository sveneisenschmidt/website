<?php

namespace Website\Tests\Integration;

use Website\Assistant\Apply;
use Website\Assistant\Chat;
use Website\Assistant\Log;
use Website\Assistant\Session;
use Website\Changelog\Log as Changelog;

/**
 * The prompt is set to a marker. It goes to Claude and nowhere else: no
 * page, no route, no Panel view of the assistant, no log.
 */
final class PromptLeakTest extends AssistantTestCase
{
    public function testThePromptAppearsNowhere(): void
    {
        // About cannot change its status in the Panel: it is created unlisted.
        $this->kirby->site()->createChild(['slug' => 'about', 'template' => 'about', 'draft' => false, 'content' => ['title' => 'About', 'text' => 'Hello.']]);
        $this->post('notes', 'note', 'a-note', 'A note');
        $this->post('trips', 'trip', 'a-draft', 'A draft', 'draft');
        $this->upload(__DIR__ . '/../fixtures/exif.jpg', 'a.jpg');
        // A chat with a card and a click, so Protokoll, Verlauf and the view have entries.
        $this->reply('tool_use', $this->tool('t1', 'propose_field', ['post' => 'photos/test-photo', 'field' => 'intro', 'value' => 'New.']));
        $this->reply('end_turn', $this->text('Steht oben.'));
        $chat = $this->chat('Mach den Anriss neu.');
        Apply::decide($this->card($chat)['id'], 'apply');
        $this->kirby->site()->update(['assistantPrompt' => self::PROMPT . ' changed']);
        $this->assertStringContainsString(self::PROMPT, json_encode($this->requests[0]), 'The marker goes to Claude.');

        $outputs = [];
        foreach ($this->kirby->site()->index(true) as $page) {
            $outputs[$page->id()] = $page->render();
        }
        foreach ($this->kirby->site()->index() as $page) {
            $outputs['/' . $page->uri()] = $this->kirby->render('/' . $page->uri())?->body();
        }
        // Routes of the site (phase 3 adds sitemap, feed and the like): every pattern without a placeholder.
        foreach ($this->kirby->option('routes', []) as $route) {
            if (is_string($route['pattern'] ?? null) && !str_contains($route['pattern'], '(:')) {
                $outputs[$route['pattern']] = $this->kirby->render('/' . $route['pattern'])?->body();
            }
        }
        $outputs['assistant view'] = json_encode(Chat::view(Session::load()));
        $outputs['protokoll'] = json_encode(Log::view());
        $outputs['verlauf'] = json_encode(Changelog::entries());
        foreach (glob($this->root . '/content/{.assistant,.changelog}/*.jsonl', GLOB_BRACE) as $file) {
            $outputs[$file] = file_get_contents($file);
        }

        $this->assertGreaterThan(10, count($outputs));
        foreach ($outputs as $where => $output) {
            $this->assertStringNotContainsString('PROMPT-CANARY', (string) $output, (string) $where);
        }
    }
}
