<?php

namespace Website\Tests\Integration;

final class ChangelogTest extends IntegrationTestCase
{
    private const EMAIL = 'sven@website.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->kirby->impersonate('kirby', fn () => $this->kirby->users()->create([
            'email' => self::EMAIL,
            'name' => 'Sven',
            'role' => 'admin',
            'password' => 'test-password-123',
        ]));
    }

    public function testSaveAsUserLogsWhoWhatWhere(): void
    {
        $photo = $this->kirby->page('photos/test-photo');
        $this->kirby->impersonate(self::EMAIL, fn () => $photo->update(['intro' => 'A new intro']));

        $entry = blog_changelog_entries()[0];
        $this->assertSame('page.update', $entry['action']);
        $this->assertSame(self::EMAIL, $entry['user']);
        $this->assertSame('Sven', $entry['name']);
        $this->assertSame('photos/test-photo', $entry['id']);
        $this->assertSame('Test Photo', $entry['title']);
        $this->assertSame('/pages/photos+test-photo', $entry['link']);
        $this->assertSame([[
            'field' => 'intro',
            'label' => 'Anriss',
            'old' => 'Test intro',
            'new' => 'A new intro',
        ]], $entry['changes']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $entry['time']);
    }

    public function testPanelSaveOfChangesVersionIsLogged(): void
    {
        // The Panel stores edits as the "changes" version; "Speichern" publishes it.
        $photo = $this->kirby->page('photos/test-photo');
        $this->kirby->impersonate(self::EMAIL, function () use ($photo) {
            $changes = $photo->version('changes');
            $changes->save([...$photo->content()->toArray(), 'text' => 'A quiet street.']);
            $changes->publish();
        });

        $entry = blog_changelog_entries()[0];
        $this->assertSame('page.update', $entry['action']);
        $this->assertSame(self::EMAIL, $entry['user']);
        $this->assertSame(['text'], array_column($entry['changes'], 'field'));
    }

    public function testUploadLogsUserAndAutomaticExif(): void
    {
        $this->kirby->impersonate(self::EMAIL, fn () => $this->post->createFile([
            'source' => __DIR__ . '/../fixtures/exif.jpg',
            'filename' => '01.jpg',
            'template' => 'image',
        ]));

        $entries = array_reverse(blog_changelog_entries());
        $actions = array_map(fn ($e) => [$e['action'], $e['name']], $entries);
        $this->assertContains(['file.create', 'Sven'], $actions);
        // Our EXIF hook writes as the kirby user: shown as "Automatisch".
        $this->assertContains(['file.update', null], $actions);
    }

    public function testDeleteIsLoggedWithoutLink(): void
    {
        $this->kirby->impersonate(self::EMAIL, fn () => $this->kirby->page('photos/test-photo')->delete(true));

        $entry = blog_changelog_entries()[0];
        $this->assertSame('page.delete', $entry['action']);
        $this->assertSame('Test Photo', $entry['title']);
        $this->assertNull($entry['link']);
    }

    public function testPasswordIsNeverLogged(): void
    {
        $user = $this->kirby->user(self::EMAIL);
        $this->kirby->impersonate('kirby', fn () => $user->changePassword('another-password-456'));

        $entry = blog_changelog_entries()[0];
        $this->assertSame('user.changePassword', $entry['action']);
        $this->assertSame([], $entry['changes']);
        $log = implode('', array_map('file_get_contents', glob($this->root . '/content/.changelog/*.jsonl')));
        $this->assertStringNotContainsString('another-password-456', $log);
        $this->assertStringNotContainsString('$2y$', $log);
    }

    public function testLogIsStateInContentAndNoPage(): void
    {
        $this->kirby->impersonate(self::EMAIL, fn () => $this->kirby->page('photos/test-photo')->update(['text' => 'Dusk.']));

        $this->assertFileExists($this->root . '/content/.changelog/' . date('Y-m') . '.jsonl');
        $this->assertSame(['photos'], $this->kirby->site()->childrenAndDrafts()->pluck('slug'));
    }

    public function testNewestFirstAndLimit(): void
    {
        $photo = $this->kirby->page('photos/test-photo');
        foreach (['A', 'B', 'C'] as $text) {
            $photo = $this->kirby->impersonate(self::EMAIL, fn () => $photo->update(['text' => $text]));
        }

        $entries = blog_changelog_entries(2);
        $this->assertCount(2, $entries);
        $this->assertSame('C', $entries[0]['changes'][0]['new']);
        $this->assertSame('B', $entries[1]['changes'][0]['new']);
    }

    public function testSaveWithoutChangeAddsNoEntry(): void
    {
        $photo = $this->kirby->page('photos/test-photo');
        $before = count(blog_changelog_entries());
        $this->kirby->impersonate(self::EMAIL, fn () => $photo->update(['intro' => 'Test intro']));
        $this->assertCount($before, blog_changelog_entries());
    }

    public function testListFieldsShowAddedAndRemovedRows(): void
    {
        $trip = $this->post('trips', 'trip', 'test-trip', 'Test Trip');
        $point = fn (string $lat, string $label) => ['label' => $label, 'lat' => $lat, 'lon' => '0.39'];

        $trip = $this->kirby->impersonate(self::EMAIL, fn () => $trip->update(['mappoints' => [$point('50.82', 'Brighton')]]));
        $entry = blog_changelog_entries(1)[0];
        $this->assertSame([[
            'field' => 'mappoints',
            'label' => 'Kartenpunkte',
            'old' => '',
            'new' => 'Brighton',
        ]], $entry['changes']);

        $this->kirby->impersonate(self::EMAIL, fn () => $trip->update(['mappoints' => [$point('50.83', 'Hove')]]));
        $entry = blog_changelog_entries(1)[0];
        $this->assertSame([['field' => 'mappoints', 'label' => 'Kartenpunkte', 'old' => 'Brighton', 'new' => 'Hove']], $entry['changes']);
    }

    public function testFileReferenceAsIdOrUuidIsNoChange(): void
    {
        $file = $this->upload($this->jpeg(100, 100), '01.jpg');
        $this->kirby->impersonate('kirby', fn () => $this->post->version('latest')->update(['cover' => '- ' . $file->id()]));
        $before = count(blog_changelog_entries());

        $this->kirby->impersonate(self::EMAIL, fn () => $this->kirby->page('photos/test-photo')->update(['cover' => [$file->uuid()->toString()]]));

        $this->assertCount($before, blog_changelog_entries());
    }

    public function testFileReferenceShowsItsName(): void
    {
        $file = $this->upload($this->jpeg(100, 100), '01.jpg');
        $this->kirby->impersonate(self::EMAIL, fn () => $this->kirby->page('photos/test-photo')->update(['cover' => [$file->uuid()->toString()]]));

        $entry = blog_changelog_entries()[0];
        $this->assertSame('Titelbild', $entry['changes'][0]['label']);
        $this->assertSame('Test Photo · 01.jpg', $entry['changes'][0]['new']);
    }

    public function testStatusAndKindAsThePanelNamesThem(): void
    {
        $photo = $this->kirby->page('photos/test-photo');
        $this->kirby->impersonate(self::EMAIL, fn () => $photo->changeStatus('draft'));

        $entry = blog_changelog_entries(1)[0];
        $this->assertSame('Foto', $entry['kind']);
        $this->assertSame([[
            'field' => 'status',
            'label' => 'Status',
            'old' => 'Veröffentlicht',
            'new' => 'Entwurf',
        ]], $entry['changes']);
    }

    public function testEveryPostTypeHasItsKindAndOtherPagesHaveNone(): void
    {
        $about = $this->kirby->impersonate('kirby', fn () => $this->kirby->site()->createChild([
            'slug' => 'about',
            'template' => 'about',
            'content' => ['title' => 'About'],
        ]));
        $pages = [
            [$this->post('trips', 'trip', 'a-trip', 'A Trip'), 'Reise'],
            [$this->post('notes', 'note', 'a-note', 'A Note'), 'Notiz'],
            [$this->post('essays', 'essay', 'an-essay', 'An Essay'), 'Essay'],
            [$this->post, 'Foto'],
            [$about, null],
        ];

        foreach ($pages as [$page, $kind]) {
            $this->kirby->impersonate(self::EMAIL, fn () => $page->update(['text' => 'Changed.']));
            $entry = blog_changelog_entries(1)[0];
            $this->assertSame($page->id(), $entry['id']);
            $this->assertSame($kind, $entry['kind'], $page->id());
        }
    }

    public function testNumberThatFollowsTheDateIsNoEntryOfItsOwn(): void
    {
        // Posts are numbered by date: a new date moves the folder number, and
        // the date change is the one entry.
        $photo = $this->kirby->page('photos/test-photo');
        $before = count(blog_changelog_entries());

        $this->kirby->impersonate(self::EMAIL, fn () => $photo->update(['date' => '2026-11-09 10:00:00']));

        $new = array_slice(blog_changelog_entries(), 0, count(blog_changelog_entries()) - $before);
        $this->assertSame(['page.update'], array_column($new, 'action'));
        $this->assertSame(['date'], array_column($new[0]['changes'], 'field'));
    }

    public function testSortingPagesWithoutDateNumberIsLogged(): void
    {
        $this->kirby->impersonate('kirby', fn () => $this->kirby->site()->createChild(['slug' => 'first', 'template' => 'default', 'content' => ['title' => 'First']])->changeStatus('listed'));
        $second = $this->kirby->impersonate('kirby', fn () => $this->kirby->site()->createChild(['slug' => 'second', 'template' => 'default', 'content' => ['title' => 'Second']])->changeStatus('listed'));
        $before = count(blog_changelog_entries());

        $this->kirby->impersonate(self::EMAIL, fn () => $second->changeSort(1));

        // The sibling that moves down is logged too.
        $new = array_slice(blog_changelog_entries(), 0, count(blog_changelog_entries()) - $before);
        $this->assertSame('page.changeNum', $new[0]['action']);
        $moved = array_values(array_filter($new, fn ($e) => $e['action'] === 'page.changeNum' && $e['id'] === 'second'));
        $this->assertCount(1, $moved);
        $this->assertSame(self::EMAIL, $moved[0]['user']);
        $this->assertSame([['field' => 'num', 'label' => 'Reihenfolge', 'old' => '2', 'new' => '1']], $moved[0]['changes']);
    }

    public function testPublishingIsOneEntry(): void
    {
        $photo = $this->kirby->impersonate('kirby', fn () => $this->kirby->page('photos/test-photo')->changeStatus('draft'));
        $before = count(blog_changelog_entries());

        $this->kirby->impersonate(self::EMAIL, fn () => $photo->changeStatus('listed'));

        $new = array_slice(blog_changelog_entries(), 0, count(blog_changelog_entries()) - $before);
        $this->assertSame(['page.changeStatus'], array_column($new, 'action'));
        $this->assertSame(['Entwurf', 'Veröffentlicht'], [$new[0]['changes'][0]['old'], $new[0]['changes'][0]['new']]);
    }
}
