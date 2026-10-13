<?php

namespace Website\Tests\Integration;

use Kirby\Cms\Page;

/**
 * Cards for existing posts: a passage, a whole field, the texts of an
 * image. A published post gets the click as unsaved changes, a draft at
 * once (spec, "Karten und Klick").
 */
final class AssistantEditTest extends AssistantTestCase
{
    /** One round with one propose call, then the end; returns the card. */
    private function propose(string $tool, array $input): array
    {
        $this->reply('tool_use', $this->tool('t1', $tool, $input));
        $this->reply('end_turn');
        return $this->card($this->chat('Bitte.'));
    }

    /** The error Claude got back for the last propose call. */
    private function refusal(string $tool, array $input): string
    {
        $this->reply('tool_use', $this->tool('t1', $tool, $input));
        $this->reply('end_turn');
        $this->chat('Bitte.');
        $result = $this->results(count($this->requests) - 1)[0];
        $this->assertTrue($result['is_error'] ?? false);
        return $result['content'];
    }

    private function page(string $id = 'photos/test-photo'): Page
    {
        return $this->kirby->site()->find('photos')->findPageOrDraft(basename($id));
    }

    public function testAFieldOfAPublishedPostGoesIntoTheChanges(): void
    {
        $card = $this->propose('propose_field', ['post' => 'photos/test-photo', 'field' => 'intro', 'value' => 'A new intro.']);
        $this->assertSame([['Anriss', 'Test intro', 'A new intro.', [], []]], $card['diff']);
        $this->assertStringContainsString('Änderungen', $card['where']);
        $this->assertSame('Test intro', $this->page()->intro()->value(), 'Nothing changes before the click.');

        blog_assistant_decide($card['id'], 'apply');

        $this->assertSame('done', blog_assistant_decisions()[$card['id']]['state']);
        $this->assertSame('Test intro', $this->page()->version('latest')->read()['intro'], 'Live stays as it was.');
        $this->assertSame('A new intro.', $this->page()->version('changes')->read()['intro']);
        $this->assertSame(['(Card "' . $card['title'] . '": applied by the user. It waits in the unsaved changes until the user saves in the Panel.)'], $this->kirby->session()->get('blog.assistant.notes'));
    }

    public function testAFieldOfADraftIsSavedAtOnce(): void
    {
        $this->post('photos', 'photo', 'draft-photo', 'Draft', 'draft');
        $card = $this->propose('propose_field', ['post' => 'photos/draft-photo', 'field' => 'title', 'value' => 'Better title']);

        blog_assistant_decide($card['id'], 'apply');

        $this->assertSame('Better title', $this->page('photos/draft-photo')->title()->value());
        $this->assertFalse($this->page('photos/draft-photo')->version('changes')->exists());
    }

    public function testFieldsAreCheckedByTheirType(): void
    {
        $this->assertStringContainsString('future', $this->refusal('propose_field', ['post' => 'photos/test-photo', 'field' => 'date', 'value' => '2099-01-01 10:00:00']));
        $this->assertStringContainsString('YYYY-MM-DD HH:MM:SS', $this->refusal('propose_field', ['post' => 'photos/test-photo', 'field' => 'date', 'value' => '2026-10-01T10:00']));
        $this->assertStringContainsString('true or false', $this->refusal('propose_field', ['post' => 'photos/test-photo', 'field' => 'pick', 'value' => 'yes']));
        $this->assertStringContainsString('propose_route', $this->refusal('propose_field', ['post' => 'photos/test-photo', 'field' => 'mappoints', 'value' => 'x']));
        $this->assertStringContainsString('HTML', $this->refusal('propose_field', ['post' => 'photos/test-photo', 'field' => 'text', 'value' => '<iframe src="x">']));
        $this->assertStringContainsString('No post', $this->refusal('propose_field', ['post' => 'search', 'field' => 'title', 'value' => 'x']));
        $this->assertNotEmpty($this->propose('propose_field', ['post' => 'photos/test-photo', 'field' => 'date', 'value' => '2026-10-01 03:17:42'])['id']);
    }

    public function testAStaleCardChangesNothing(): void
    {
        $card = $this->propose('propose_field', ['post' => 'photos/test-photo', 'field' => 'text', 'value' => 'New text.']);
        $this->page()->update(['text' => 'Sven wrote this meanwhile.']);

        blog_assistant_decide($card['id'], 'apply');

        $decision = blog_assistant_decisions()[$card['id']];
        $this->assertSame('stale', $decision['state']);
        $this->assertStringContainsString('Nichts geändert', $decision['warn']);
        $this->assertSame('Sven wrote this meanwhile.', $this->page()->text()->value());
        $this->assertFalse($this->page()->version('changes')->exists());
        $this->assertStringContainsString('the field changed since the proposal', $this->kirby->session()->get('blog.assistant.notes')[0]);
    }

    public function testUnsavedChangesAreTheBeforeAndGetTheNewValue(): void
    {
        $page = $this->page();
        $page->version('changes')->save([...$page->version('latest')->read(), 'text' => 'Not saved yet.']);
        $card = $this->propose('propose_field', ['post' => 'photos/test-photo', 'field' => 'text', 'value' => 'Better.']);
        $this->assertSame('Not saved yet.', $card['diff'][0][1]);
        $this->assertTrue($card['unsaved']);

        blog_assistant_decide($card['id'], 'apply');

        $this->assertSame('Better.', $this->page()->version('changes')->read()['text']);
        $this->assertSame('Test intro', $this->page()->version('changes')->read()['intro']);
    }

    public function testAPassageWithSeveralVersions(): void
    {
        $this->page()->update(['text' => "We drove north.\n\nIt rained all day."]);
        $card = $this->propose('propose_edit', ['post' => 'photos/test-photo', 'field' => 'text', 'passage' => 'It rained all day.', 'versions' => ['Rain, all day.', 'It rained.', 'All day: rain.']]);
        $this->assertSame(['Rain, all day.', 'It rained.', 'All day: rain.'], $card['versions']);
        $this->assertSame('Text', $card['field']);

        blog_assistant_decide($card['id'], 'apply', 1);

        $this->assertSame("We drove north.\n\nIt rained.", $this->page()->version('changes')->read()['text']);
        $this->assertStringContainsString('took version 2', $this->kirby->session()->get('blog.assistant.notes')[0]);
    }

    public function testAPassageMustOccurExactlyOnce(): void
    {
        $this->page()->update(['text' => 'Rain. Rain.']);
        $this->assertStringContainsString('2 times', $this->refusal('propose_edit', ['post' => 'photos/test-photo', 'field' => 'text', 'passage' => 'Rain.', 'versions' => ['Sun.']]));
        $this->assertStringContainsString('does not occur', $this->refusal('propose_edit', ['post' => 'photos/test-photo', 'field' => 'text', 'passage' => 'Snow.', 'versions' => ['Sun.']]));
        $this->assertStringContainsString('not allowed', $this->refusal('propose_edit', ['post' => 'photos/test-photo', 'field' => 'text', 'passage' => 'Rain. Rain.', 'versions' => ['See [x](https://evil.test).']]));
    }

    public function testAStalePassageChangesNothing(): void
    {
        $this->page()->update(['text' => 'It rained all day.']);
        $card = $this->propose('propose_edit', ['post' => 'photos/test-photo', 'field' => 'text', 'passage' => 'rained', 'versions' => ['poured']]);
        $this->page()->update(['text' => 'It rained and rained.']);

        blog_assistant_decide($card['id'], 'apply');

        $this->assertSame('stale', blog_assistant_decisions()[$card['id']]['state']);
        $this->assertSame('It rained and rained.', $this->page()->text()->value());
    }

    public function testALinkFromSvensMessageOrTheFieldIsAllowed(): void
    {
        $this->page()->update(['text' => 'See [old](https://old.test).']);
        $this->reply('tool_use', $this->tool('t1', 'propose_field', ['post' => 'photos/test-photo', 'field' => 'text', 'value' => 'See [old](https://old.test) and [new](https://new.test/page).']));
        $this->reply('end_turn');

        $chat = $this->chat('Verlinke https://new.test/page bitte.');

        $this->assertSame('field', $this->card($chat)['kind']);
    }

    public function testAVersionIsCheckedInItsField(): void
    {
        // An old reference definition renders nothing until a version uses it.
        $this->page()->update(['text' => "It rained.\n\n[1]: //evil.example/"]);
        $this->assertStringContainsString('not allowed', $this->refusal('propose_edit', ['post' => 'photos/test-photo', 'field' => 'text', 'passage' => 'It rained.', 'versions' => ['It [rained][1].']]));
    }

    public function testOldMarkupOfAFieldStaysEditable(): void
    {
        // Imported posts keep raw HTML.
        $this->page()->update(['text' => "<iframe src=\"https://video.test/embed/1\"></iframe>\n\nIt rained all day."]);
        $card = $this->propose('propose_edit', ['post' => 'photos/test-photo', 'field' => 'text', 'passage' => 'It rained all day.', 'versions' => ['It poured.']]);
        $this->assertSame('edit', $card['kind']);
        $this->assertStringContainsString('HTML', $this->refusal('propose_edit', ['post' => 'photos/test-photo', 'field' => 'text', 'passage' => 'It rained all day.', 'versions' => ['<iframe src="https://video.test/embed/2"></iframe>']]));
    }

    public function testTheCardKnowsTheLinksOfItsTexts(): void
    {
        $uuid = $this->post->uuid()->toString();
        $this->page()->update(['text' => 'See [old](https://old.test).']);
        $value = "See [new](https://new.test/page), https://new.test/page and (link: $uuid text: this photo).";
        $this->reply('tool_use', $this->tool('t1', 'propose_field', ['post' => 'photos/test-photo', 'field' => 'text', 'value' => $value]));
        $this->reply('end_turn');

        $card = $this->card($this->chat('Verlinke https://new.test/page bitte.'));

        $this->assertSame(['Text', 'See [old](https://old.test).', $value, ['https://old.test'], ['https://new.test/page', "(link: $uuid text: this photo)"]], $card['diff'][0]);
    }

    public function testTheTextsOfAnImage(): void
    {
        $this->upload(__DIR__ . '/../fixtures/exif.jpg', 'a.jpg');
        $card = $this->propose('propose_file_fields', ['file' => 'photos/test-photo/a.jpg', 'fields' => [['name' => 'alt', 'value' => 'A dog on a beach'], ['name' => 'photographer', 'value' => 'Nadine']]]);
        $this->assertSame([['Alternativtext', '', 'A dog on a beach', [], []], ['Fotografiert von', '', 'Nadine', [], []]], $card['diff']);

        blog_assistant_decide($card['id'], 'apply');

        $file = $this->page()->file('a.jpg');
        $this->assertSame('A dog on a beach', $file->version('changes')->read()['alt']);
        $this->assertSame('TEST-CAM', $file->camera()->value());
        $this->assertStringContainsString('caption', $this->refusal('propose_file_fields', ['file' => 'photos/test-photo/a.jpg', 'fields' => [['name' => 'caption', 'value' => '(image: a.jpg)']]]));
    }

    public function testADecisionIsApplyOrDiscard(): void
    {
        $card = $this->propose('propose_field', ['post' => 'photos/test-photo', 'field' => 'intro', 'value' => 'X']);

        foreach (['', 'yes', 'Apply'] as $action) {
            try {
                blog_assistant_decide($card['id'], $action);
                $this->fail("Took the action \"$action\"");
            } catch (\InvalidArgumentException) {
            }
        }

        $this->assertArrayNotHasKey($card['id'], blog_assistant_decisions());
        $this->assertFalse($this->page()->version('changes')->exists());
    }

    public function testADiscardedCardChangesNothingAndIsNamedNextTime(): void
    {
        $card = $this->propose('propose_field', ['post' => 'photos/test-photo', 'field' => 'intro', 'value' => 'X']);

        blog_assistant_decide($card['id'], 'discard');
        $this->reply('end_turn', $this->text('Gut.'));
        $this->chat('Danke.');

        $this->assertFalse($this->page()->version('changes')->exists());
        $this->assertStringStartsWith('(Card "' . $card['title'] . '": discarded by the user.)', $this->lastText(end($this->requests)));
        $this->assertSame(['decision', 'answer'], array_column(array_slice(blog_assistant_log_read(), 1, 2), 'type'));
        $this->assertSame('discarded', blog_assistant_log_read()[1]['decision']);
    }

    public function testADecisionDuringARoundIsKept(): void
    {
        $this->reply('tool_use', $this->tool('t1', 'propose_field', ['post' => 'photos/test-photo', 'field' => 'intro', 'value' => 'X']), $this->tool('t2', 'read_post', ['post' => 'photos/test-photo']));
        $card = $this->card(blog_assistant_send('Mach was und lies.'));
        $running = blog_assistant_load();

        blog_assistant_decide($card['id'], 'discard');
        // The round that ran meanwhile stores its copy of the chat.
        blog_assistant_keep($running);

        $this->assertSame('discarded', blog_assistant_decisions()[$card['id']]['state']);
        $this->assertSame(0, blog_assistant_view(blog_assistant_load())['open']);
    }

    public function testNoProposalRepeatsThePrompt(): void
    {
        // A marker of 10 words; any 8 of them in a row are a run of the prompt.
        $this->kirby->site()->update(['assistantPrompt' => 'PROMPT-CANARY-7f3a alpha bravo charlie delta echo foxtrot golf hotel india']);
        $run = 'Alpha, *bravo* charlie delta echo foxtrot golf hotel';
        $this->upload(__DIR__ . '/../fixtures/exif.jpg', 'a.jpg');

        $this->assertStringContainsString('prompt', $this->refusal('propose_field', ['post' => 'photos/test-photo', 'field' => 'text', 'value' => "We rode. $run."]));
        $this->assertStringContainsString('prompt', $this->refusal('propose_field', ['post' => 'photos/test-photo', 'field' => 'title', 'value' => $run]));
        $this->assertStringContainsString('prompt', $this->refusal('propose_file_fields', ['file' => 'photos/test-photo/a.jpg', 'fields' => [['name' => 'alt', 'value' => $run]]]));
        $this->assertStringContainsString('prompt', $this->refusal('propose_post', [
            'type' => 'note', 'slug' => 'alpha-bravo-charlie-delta-echo-foxtrot-golf-hotel', 'date' => '2026-10-01 20:40:00', 'title' => 'T', 'intro' => '', 'text' => '',
            'pick' => false, 'trip' => '', 'photos' => [], 'cover' => '', 'route' => ['stations' => [], 'gpx' => '', 'markers' => [], 'zoom' => false],
        ]));
        $this->assertSame('field', $this->propose('propose_field', ['post' => 'photos/test-photo', 'field' => 'text', 'value' => 'Alpha bravo charlie delta echo foxtrot golf, and hotel.'])['kind'], 'Seven words in a row are no run.');
    }

    public function testToolsJudgeNoStyle(): void
    {
        $this->page()->update(['text' => 'A b c.']);
        $this->assertSame('field', $this->propose('propose_field', ['post' => 'photos/test-photo', 'field' => 'title', 'value' => 'WOW!!! 🚲 all caps TITLE?!'])['kind']);
        $this->assertCount(6, $this->propose('propose_edit', ['post' => 'photos/test-photo', 'field' => 'text', 'passage' => 'b', 'versions' => ['1', '2', '3', '4', '5', 'a very long version with no punctuation at all and emoji 🙂']])['versions']);
    }
}
