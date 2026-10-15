<?php

namespace Website\Tests\Integration;

use Kirby\Cms\Page;
use Website\Assistant\Apply;
use Website\Assistant\Chat;
use Website\Assistant\Error;
use Website\Assistant\Log;
use Website\Assistant\Session;

/**
 * Cards that change an existing post: a passage, a whole field, or the texts
 * of an image. Applying a card to a published post stores the change as
 * unsaved changes (the version "changes"). A draft gets it at once.
 */
final class AssistantEditTest extends AssistantTestCase
{
    /**
     * Runs one round with one propose call and a final answer. Returns the
     * card.
     *
     * @param array<mixed> $input
     * @return array<mixed>
     */
    private function propose(string $tool, array $input): array
    {
        $this->reply('tool_use', $this->tool('t1', $tool, $input));
        $this->reply('end_turn');
        return $this->card($this->chat('Bitte.'));
    }

    /**
     * Returns the error that Claude got back for the last propose call.
     *
     * @param array<mixed> $input
     */
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
        $this->assertSame([['label' => 'Anriss', 'before' => 'Test intro', 'after' => 'A new intro.', 'marksBefore' => [], 'marksAfter' => []]], $card['diff']);
        $this->assertStringContainsString('Änderungen', $card['where']);
        $this->assertSame('Test intro', $this->page()->intro()->value(), 'Nothing changes before the click.');

        Apply::decide($card['id'], 'apply');

        $this->assertSame('done', Session::decisions()[$card['id']]['state']);
        $this->assertSame('Test intro', $this->page()->version('latest')->read()['intro'], 'Live stays as it was.');
        $this->assertSame('A new intro.', $this->page()->version('changes')->read()['intro']);
        $this->assertSame(['(Card "' . $card['title'] . '": applied by the user. It waits in the unsaved changes until the user saves in the Panel.)'], $this->kirby->session()->get('blog.assistant.notes'));
    }

    public function testAFieldOfADraftIsSavedAtOnce(): void
    {
        $this->post('photos', 'photo', 'draft-photo', 'Draft', 'draft');
        $card = $this->propose('propose_field', ['post' => 'photos/draft-photo', 'field' => 'title', 'value' => 'Better title']);

        Apply::decide($card['id'], 'apply');

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

    /** Autolinks and a "<" without a ">" after it are Markdown and text, not HTML. */
    public function testAutolinksAndALessThanSignAreNoHtml(): void
    {
        foreach (['See <https://example.com/a?b=1>.', 'Mail <name@example.com>.', 'Mail <mailto:name@example.com>.', 'a<b', '1 < 2', 'I <3 bikes'] as $value) {
            $this->assertSame('field', $this->propose('propose_field', ['post' => 'photos/test-photo', 'field' => 'text', 'value' => $value])['kind'], $value);
        }
        // The last two passed the first version of the check (review of 15 Oct 2026).
        foreach (['<b>bold</b>', '</p>', '<!-- hidden', '<javascript://%0aalert(1)>', '<x:y onmouseover=alert(1)>', "a <mailto:x='/onmouseover=alert`1`//'y=z@-> b", '<><https://x/onmouseover=alert(1)>'] as $value) {
            $this->assertStringContainsString('HTML', $this->refusal('propose_field', ['post' => 'photos/test-photo', 'field' => 'text', 'value' => $value]), $value);
        }
    }

    /**
     * A version without raw HTML can still make some with the text around
     * the passage: "<b x" before a later ">" is a tag, and a version inside
     * an attribute changes an existing tag. The proposal refuses it, and so
     * does the click, because the text around the passage can change.
     */
    public function testAVersionMustNotMakeRawHtmlWithItsField(): void
    {
        $this->page()->update(['text' => "<iframe src=\"https://video.test/embed/1\"></iframe>\n\nIt rained all day. Sun > rain."]);
        $this->assertStringContainsString('raw HTML', $this->refusal('propose_edit', ['post' => 'photos/test-photo', 'field' => 'text', 'passage' => 'It rained all day.', 'versions' => ['<b onmouseover=alert(1) x']]));
        $this->assertStringContainsString('raw HTML', $this->refusal('propose_edit', ['post' => 'photos/test-photo', 'field' => 'text', 'passage' => 'https://video.test/embed/1', 'versions' => ['x" onload="alert(1)']]));

        $card = $this->propose('propose_edit', ['post' => 'photos/test-photo', 'field' => 'text', 'passage' => 'Sun > rain.', 'versions' => ['Sun <b x']]);
        $this->page()->update(['text' => "<iframe src=\"https://video.test/embed/1\"></iframe>\n\nIt rained all day. Sun > rain. y >"]);
        Apply::decide($card['id'], 'apply');

        $this->assertSame('stale', Session::decisions()[$card['id']]['state']);
        $this->assertStringContainsString('rohes HTML', Session::decisions()[$card['id']]['warn']);
        $this->assertFalse($this->page()->version('changes')->exists());
    }

    public function testAStaleCardChangesNothing(): void
    {
        $card = $this->propose('propose_field', ['post' => 'photos/test-photo', 'field' => 'text', 'value' => 'New text.']);
        $this->page()->update(['text' => 'The author wrote this meanwhile.']);

        Apply::decide($card['id'], 'apply');

        $decision = Session::decisions()[$card['id']];
        $this->assertSame('stale', $decision['state']);
        $this->assertStringContainsString('Nichts geändert', $decision['warn']);
        $this->assertSame('The author wrote this meanwhile.', $this->page()->text()->value());
        $this->assertFalse($this->page()->version('changes')->exists());
        $this->assertStringContainsString('the field changed since the proposal', $this->kirby->session()->get('blog.assistant.notes')[0]);
    }

    public function testUnsavedChangesAreTheBeforeAndGetTheNewValue(): void
    {
        $page = $this->page();
        $page->version('changes')->save([...$page->version('latest')->read(), 'text' => 'Not saved yet.']);
        $card = $this->propose('propose_field', ['post' => 'photos/test-photo', 'field' => 'text', 'value' => 'Better.']);
        $this->assertSame('Not saved yet.', $card['diff'][0]['before']);
        $this->assertTrue($card['unsaved']);

        Apply::decide($card['id'], 'apply');

        $this->assertSame('Better.', $this->page()->version('changes')->read()['text']);
        $this->assertSame('Test intro', $this->page()->version('changes')->read()['intro']);
    }

    public function testAPassageWithSeveralVersions(): void
    {
        $this->page()->update(['text' => "We drove north.\n\nIt rained all day."]);
        $card = $this->propose('propose_edit', ['post' => 'photos/test-photo', 'field' => 'text', 'passage' => 'It rained all day.', 'versions' => ['Rain, all day.', 'It rained.', 'All day: rain.']]);
        $this->assertSame(['Rain, all day.', 'It rained.', 'All day: rain.'], $card['versions']);
        $this->assertSame('Text', $card['field']);

        Apply::decide($card['id'], 'apply', 1);

        $this->assertSame("We drove north.\n\nIt rained.", $this->page()->version('changes')->read()['text']);
        $this->assertStringContainsString('took version 2', $this->kirby->session()->get('blog.assistant.notes')[0]);
    }

    public function testAPassageMustOccurExactlyOnce(): void
    {
        $this->page()->update(['text' => 'Rain. Rain.']);
        $this->assertStringContainsString('2 times', $this->refusal('propose_edit', ['post' => 'photos/test-photo', 'field' => 'text', 'passage' => 'Rain.', 'versions' => ['Sun.']]));
        $this->assertStringContainsString('does not occur', $this->refusal('propose_edit', ['post' => 'photos/test-photo', 'field' => 'text', 'passage' => 'Snow.', 'versions' => ['Sun.']]));
    }

    public function testAStalePassageChangesNothing(): void
    {
        $this->page()->update(['text' => 'It rained all day.']);
        $card = $this->propose('propose_edit', ['post' => 'photos/test-photo', 'field' => 'text', 'passage' => 'rained', 'versions' => ['poured']]);
        $this->page()->update(['text' => 'It rained and rained.']);

        Apply::decide($card['id'], 'apply');

        $this->assertSame('stale', Session::decisions()[$card['id']]['state']);
        $this->assertSame('It rained and rained.', $this->page()->text()->value());
    }

    public function testOldMarkupOfAFieldStaysEditable(): void
    {
        // Some posts contain raw HTML, such as an embedded video.
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

        $this->assertSame(['label' => 'Text', 'before' => 'See [old](https://old.test).', 'after' => $value, 'marksBefore' => ['https://old.test'], 'marksAfter' => ['https://new.test/page', "(link: $uuid text: this photo)"]], $card['diff'][0]);
    }

    public function testTheTextsOfAnImage(): void
    {
        $this->upload(__DIR__ . '/../fixtures/exif.jpg', 'a.jpg');
        $card = $this->propose('propose_file_fields', ['file' => 'photos/test-photo/a.jpg', 'fields' => [['name' => 'alt', 'value' => 'A dog on a beach'], ['name' => 'photographer', 'value' => 'Nadine']]]);
        $this->assertSame([
            ['label' => 'Alternativtext', 'before' => '', 'after' => 'A dog on a beach', 'marksBefore' => [], 'marksAfter' => []],
            ['label' => 'Fotografiert von', 'before' => '', 'after' => 'Nadine', 'marksBefore' => [], 'marksAfter' => []],
        ], $card['diff']);

        Apply::decide($card['id'], 'apply');

        $file = $this->page()->file('a.jpg');
        $this->assertSame('A dog on a beach', $file->version('changes')->read()['alt']);
        $this->assertSame('TEST-CAM', $file->camera()->value());
    }

    public function testADecisionIsApplyOrDiscard(): void
    {
        $card = $this->propose('propose_field', ['post' => 'photos/test-photo', 'field' => 'intro', 'value' => 'X']);

        foreach (['', 'yes', 'Apply'] as $action) {
            try {
                Apply::decide($card['id'], $action);
                $this->fail("Took the action \"$action\"");
            } catch (Error $e) {
                $this->assertSame(['chat', 'Unbekannte Entscheidung'], [$e->kind, $e->getMessage()]);
            }
        }

        $this->assertArrayNotHasKey($card['id'], Session::decisions());
        $this->assertFalse($this->page()->version('changes')->exists());
    }

    public function testADiscardedCardChangesNothingAndIsNamedNextTime(): void
    {
        $card = $this->propose('propose_field', ['post' => 'photos/test-photo', 'field' => 'intro', 'value' => 'X']);

        Apply::decide($card['id'], 'discard');
        $this->reply('end_turn', $this->text('Gut.'));
        $this->chat('Danke.');

        $this->assertFalse($this->page()->version('changes')->exists());
        $this->assertStringStartsWith('(Card "' . $card['title'] . '": discarded by the user.)', $this->lastText(end($this->requests)));
        $this->assertSame(['decision', 'answer'], array_column(array_slice(Log::read(), 1, 2), 'type'));
        $this->assertSame('discarded', Log::read()[1]['decision']);
    }

    public function testADecisionDuringARoundIsKept(): void
    {
        $this->reply('tool_use', $this->tool('t1', 'propose_field', ['post' => 'photos/test-photo', 'field' => 'intro', 'value' => 'X']), $this->tool('t2', 'read_post', ['post' => 'photos/test-photo']));
        $card = $this->card(Chat::send('Mach was und lies.'));
        $running = Session::load();

        Apply::decide($card['id'], 'discard');
        // The round that ran meanwhile stores its copy of the chat.
        Chat::keep($running);

        $this->assertSame('discarded', Session::decisions()[$card['id']]['state']);
        $this->assertSame(0, Chat::view(Session::load())['open']);
    }

    public function testToolsDoNotCheckStyle(): void
    {
        $this->page()->update(['text' => 'A b c.']);
        $this->assertSame('field', $this->propose('propose_field', ['post' => 'photos/test-photo', 'field' => 'title', 'value' => 'WOW!!! 🚲 all caps TITLE?!'])['kind']);
        $this->assertCount(6, $this->propose('propose_edit', ['post' => 'photos/test-photo', 'field' => 'text', 'passage' => 'b', 'versions' => ['1', '2', '3', '4', '5', 'a very long version with no punctuation at all and emoji 🙂']])['versions']);
    }
}
