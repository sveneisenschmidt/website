<?php

namespace Website\Tests\Integration;

use Kirby\Cms\Page;
use Website\Assistant\Apply;
use Website\Assistant\Chat;
use Website\Assistant\Content;
use Website\Assistant\Error;
use Website\Assistant\Log;
use Website\Assistant\Session;
use Website\Blog\Redirects;

/**
 * Cards that change an existing post: a passage, a whole field, the texts
 * of an image, or the slug. Applying a card to a published post stores the
 * change as unsaved changes (the version "changes"). A draft gets it at
 * once. A new slug of a published post goes live at once, with or without a
 * redirect from the old URL.
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

    public function testANewSlugOfAPublishedPostGoesLiveWithARedirect(): void
    {
        $card = $this->propose('propose_slug', ['post' => 'photos/test-photo', 'slug' => 'new-name', 'redirect' => true]);
        $this->assertSame([['label' => 'URL', 'before' => '/2026-10-07-test-photo/', 'after' => '/2026-10-07-new-name/', 'marksBefore' => [], 'marksAfter' => []]], $card['diff']);
        $this->assertStringContainsString('sofort', $card['where']);
        $this->assertNull($this->kirby->site()->find('photos')->findPageOrDraft('new-name'), 'Nothing changes before the click.');

        Apply::decide($card['id'], 'apply');

        $this->assertSame('done', Session::decisions()[$card['id']]['state']);
        $this->assertNull($this->kirby->site()->find('photos')->findPageOrDraft('test-photo'));
        $this->assertSame('Test Photo', $this->page('photos/new-name')->title()->value());
        $this->assertSame([['from' => '/2026-10-07-test-photo/', 'to' => '/2026-10-07-new-name/', 'rss' => 'keep']], Redirects::all());
        $this->assertSame(['(Card "' . $card['title'] . '": applied by the user. New id: photos/new-name. The old URL redirects to the new one.)'], $this->kirby->session()->get('blog.assistant.notes'));
    }

    /** A post published by mistake gets a new URL at once. Nobody links the old one yet. */
    public function testANewSlugWithoutRedirect(): void
    {
        Redirects::write([['from' => '/posts/daily-photo-test/', 'to' => '/2026-10-07-test-photo/', 'rss' => 'keep']]);
        $card = $this->propose('propose_slug', ['post' => 'photos/test-photo', 'slug' => 'new-name', 'redirect' => false]);
        $this->assertStringContainsString('leitet nicht weiter', $card['where']);

        Apply::decide($card['id'], 'apply');

        $this->assertSame('Test Photo', $this->page('photos/new-name')->title()->value());
        $this->assertSame([['from' => '/posts/daily-photo-test/', 'to' => '/2026-10-07-new-name/', 'rss' => 'keep']], Redirects::all(), 'An older redirect follows the post.');
        $this->assertStringContainsString('The old URL has no redirect.', $this->kirby->session()->get('blog.assistant.notes')[0]);
    }

    public function testANewSlugOfADraftLeavesNoRedirect(): void
    {
        $this->post('photos', 'photo', 'draft-photo', 'Draft', 'draft');
        $card = $this->propose('propose_slug', ['post' => 'photos/draft-photo', 'slug' => 'better-draft', 'redirect' => true]);
        $this->assertStringContainsString('Entwurf', $card['where']);

        Apply::decide($card['id'], 'apply');

        $this->assertTrue($this->page('photos/better-draft')->isDraft());
        $this->assertSame([], Redirects::all());
    }

    /** A draft gets no redirect, so its card removes none, not even an older one with the same two URLs. */
    public function testANewSlugOfADraftWithoutRedirectKeepsOlderRedirects(): void
    {
        $this->post('photos', 'photo', 'draft-photo', 'Draft', 'draft');
        $older = [['from' => '/2026-10-07-draft-photo/', 'to' => '/2026-10-07-better-draft/', 'rss' => 'keep']];
        Redirects::write($older);
        $card = $this->propose('propose_slug', ['post' => 'photos/draft-photo', 'slug' => 'better-draft', 'redirect' => false]);

        Apply::decide($card['id'], 'apply');

        $this->assertTrue($this->page('photos/better-draft')->isDraft());
        $this->assertSame($older, Redirects::all());
    }

    /**
     * Router answers a redirect before it looks for a post. So the new URL
     * must not be the old URL of a post, else the post is out of reach once
     * it is published. A published post can take its own old URL back.
     */
    public function testANewUrlMustNotBeAnOldUrl(): void
    {
        Redirects::write([['from' => '/2026-10-07-free-name/', 'to' => '/2026-10-07-test-photo/', 'rss' => 'keep']]);
        $this->post('photos', 'photo', 'draft-photo', 'Draft', 'draft');

        $this->assertSame('The URL /2026-10-07-free-name/ is an old URL and redirects to /2026-10-07-test-photo/. The URL must be unique.', $this->refusal('propose_slug', ['post' => 'photos/draft-photo', 'slug' => 'free-name', 'redirect' => true]));

        $card = $this->propose('propose_slug', ['post' => 'photos/test-photo', 'slug' => 'free-name', 'redirect' => true]);
        Apply::decide($card['id'], 'apply');

        $this->assertSame('done', Session::decisions()[$card['id']]['state']);
        $this->assertSame([['from' => '/2026-10-07-test-photo/', 'to' => '/2026-10-07-free-name/']], Redirects::all());
    }

    public function testASlugCardIsStaleWhenItsNewUrlRedirects(): void
    {
        $card = $this->propose('propose_slug', ['post' => 'photos/test-photo', 'slug' => 'free-name', 'redirect' => true]);
        Redirects::write([['from' => '/2026-10-07-free-name/', 'to' => '/2026-10-07-other-post/']]);

        Apply::decide($card['id'], 'apply');

        $this->assertSame('stale', Session::decisions()[$card['id']]['state']);
        $this->assertStringContainsString('the new URL redirects to /2026-10-07-other-post/ now', $this->kirby->session()->get('blog.assistant.notes')[0]);
        $this->assertSame('Test Photo', $this->page()->title()->value());
    }

    /** The card shows the old and the new URL, and both have the saved date in them. */
    public function testASlugCardIsStaleAfterANewDate(): void
    {
        $card = $this->propose('propose_slug', ['post' => 'photos/test-photo', 'slug' => 'new-name', 'redirect' => false]);
        $this->page()->update(['date' => '2026-10-06 20:00:00']);

        Apply::decide($card['id'], 'apply');

        $this->assertSame('stale', Session::decisions()[$card['id']]['state']);
        $this->assertStringContainsString('the URL changed since the proposal', $this->kirby->session()->get('blog.assistant.notes')[0]);
        $this->assertSame('/2026-10-06-test-photo/', $this->page()->postPath());
    }

    /** The card of a draft promises no live change. After the author publishes the draft, the click changes nothing. */
    public function testASlugCardOfADraftIsStaleAfterPublishing(): void
    {
        $draft = $this->post('photos', 'photo', 'draft-photo', 'Draft', 'draft');
        $card = $this->propose('propose_slug', ['post' => 'photos/draft-photo', 'slug' => 'better-draft', 'redirect' => false]);
        $draft->changeStatus('listed');

        Apply::decide($card['id'], 'apply');

        $this->assertSame('stale', Session::decisions()[$card['id']]['state']);
        $this->assertStringContainsString('the status of the post changed', $this->kirby->session()->get('blog.assistant.notes')[0]);
        $this->assertSame('Draft', $this->page('photos/draft-photo')->title()->value());
    }

    public function testSlugsAreChecked(): void
    {
        $this->post('notes', 'note', 'other-post', 'Other');
        $this->assertStringContainsString('a-z', $this->refusal('propose_slug', ['post' => 'photos/test-photo', 'slug' => 'New Name', 'redirect' => true]));
        $this->assertStringContainsString('a-z', $this->refusal('propose_slug', ['post' => 'photos/test-photo', 'slug' => 'new--name', 'redirect' => true]));
        $this->assertStringContainsString('already', $this->refusal('propose_slug', ['post' => 'photos/test-photo', 'slug' => 'test-photo', 'redirect' => true]));
        $this->assertStringContainsString('notes/other-post', $this->refusal('propose_slug', ['post' => 'photos/test-photo', 'slug' => 'other-post', 'redirect' => true]));
        $this->assertStringContainsString('taken by notes.', $this->refusal('propose_slug', ['post' => 'photos/test-photo', 'slug' => 'notes', 'redirect' => true]), 'A section is a top-level page.');
        $this->kirby->site()->createChild(['slug' => 'about', 'template' => 'about', 'draft' => false, 'content' => ['title' => 'About']]);
        $this->assertStringContainsString('Only a post', $this->refusal('propose_slug', ['post' => 'about', 'slug' => 'new-name', 'redirect' => true]));
        $this->assertStringContainsString('No post', $this->refusal('propose_slug', ['post' => 'photos/typo', 'slug' => 'new-name', 'redirect' => true]));
    }

    public function testAStaleSlugCardChangesNothing(): void
    {
        $renamed = $this->propose('propose_slug', ['post' => 'photos/test-photo', 'slug' => 'new-name', 'redirect' => true]);
        $taken = $this->propose('propose_slug', ['post' => 'photos/test-photo', 'slug' => 'free-name', 'redirect' => true]);
        $this->post('notes', 'note', 'free-name', 'Free');
        Apply::decide($taken['id'], 'apply');
        $this->page()->changeSlug('by-hand');
        Apply::decide($renamed['id'], 'apply');

        $this->assertSame('stale', Session::decisions()[$taken['id']]['state']);
        $this->assertStringContainsString('the slug is taken by notes/free-name now', $this->kirby->session()->get('blog.assistant.notes')[0]);
        $this->assertSame('stale', Session::decisions()[$renamed['id']]['state']);
        $this->assertStringContainsString('the URL changed since the proposal', $this->kirby->session()->get('blog.assistant.notes')[1]);
        $this->assertSame('Test Photo', $this->page('photos/by-hand')->title()->value());
        $this->assertSame([['from' => '/2026-10-07-test-photo/', 'to' => '/2026-10-07-by-hand/', 'rss' => 'keep']], Redirects::all());
    }

    /**
     * Claude can propose a slug and other changes of the same post in one
     * answer. Each card of every kind still applies after the slug card.
     */
    public function testOpenCardsFindTheirPostAfterANewSlug(): void
    {
        $this->upload(__DIR__ . '/../fixtures/exif.jpg', 'a.jpg');
        $this->osrm[] = [200, json_encode(['code' => 'Ok', 'routes' => [['distance' => 1000, 'duration' => 60, 'geometry' => ['type' => 'LineString', 'coordinates' => [[12.1, 51.1], [12.2, 51.2]]]]]])];
        $stations = [['lat' => 51.1, 'lon' => 12.1, 'label' => 'A', 'marker' => true], ['lat' => 51.2, 'lon' => 12.2, 'label' => 'B', 'marker' => false]];
        $this->reply(
            'tool_use',
            $this->tool('t1', 'propose_slug', ['post' => 'photos/test-photo', 'slug' => 'new-name', 'redirect' => true]),
            $this->tool('t2', 'propose_field', ['post' => 'photos/test-photo', 'field' => 'title', 'value' => 'New Name']),
            $this->tool('t3', 'propose_file_fields', ['file' => 'photos/test-photo/a.jpg', 'fields' => [['name' => 'alt', 'value' => 'A dog']]]),
            $this->tool('t4', 'propose_edit', ['post' => 'photos/test-photo', 'field' => 'intro', 'passage' => 'Test intro', 'versions' => ['New intro']]),
            $this->tool('t5', 'propose_route', ['post' => 'photos/test-photo', 'route' => ['stations' => $stations, 'gpx' => '', 'markers' => [], 'zoom' => false]]),
        );
        $this->reply('end_turn');
        $chat = $this->chat('Benenne den Beitrag um.');
        $ids = array_column(array_values(array_filter($chat->items, fn($i) => $i['type'] === 'card')), 'id');

        foreach ($ids as $id) {
            Apply::decide($id, 'apply');
            $this->assertSame('done', Session::decisions()[$id]['state'], $chat->proposals[$id]['title']);
        }

        $page = $this->page('photos/new-name');
        $this->assertSame('New Name', $page->version('changes')->read()['title']);
        $this->assertSame('A dog', $page->file('a.jpg')->version('changes')->read()['alt']);
        $this->assertSame('New intro', $page->version('changes')->read()['intro']);
        $this->assertSame([['label' => 'A', 'lat' => '51.1', 'lon' => '12.1']], Content::list($page->version('changes')->read()['mappoints']));
    }

    /** A card names a file by its UUID. A UUID of a file outside the posts and About finds nothing. */
    public function testAFileUuidOutsideThePostsFindsNothing(): void
    {
        $file = $this->upload(__DIR__ . '/../fixtures/exif.jpg', 'b.jpg', $this->kirby->page('photos'));

        $this->assertStringContainsString('No image found', $this->refusal('propose_file_fields', ['file' => $file->uuid()->toString(), 'fields' => [['name' => 'alt', 'value' => 'x']]]));
        $this->assertSame('a.jpg', Content::file($this->upload(__DIR__ . '/../fixtures/exif.jpg', 'a.jpg')->uuid()->toString())?->filename());
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
