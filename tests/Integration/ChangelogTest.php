<?php

namespace Website\Tests\Integration;

use Website\Changelog\Log;

final class ChangelogTest extends IntegrationTestCase
{
    private const EMAIL = 'author@website.test';

    /** Kirby writes every failed login to the PHP error log, and PHPUnit counts that as output. */
    protected array $options = ['auth' => ['debug' => false]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->kirby->impersonate('kirby', fn() => $this->kirby->users()->create([
            'email' => self::EMAIL,
            'name' => 'Author',
            'role' => 'admin',
            'password' => 'test-password-123',
        ]));
    }

    public function testSaveAsUserLogsWhoWhatWhere(): void
    {
        $photo = $this->kirby->page('photos/test-photo');
        $this->kirby->impersonate(self::EMAIL, fn() => $photo->update(['intro' => 'A new intro']));

        $entry = Log::entries()[0];
        $this->assertSame('page.update', $entry['action']);
        $this->assertSame(self::EMAIL, $entry['user']);
        $this->assertSame('Author', $entry['name']);
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
        // The Panel stores an edit in the version "changes". The button
        // "Speichern" publishes it.
        $photo = $this->kirby->page('photos/test-photo');
        $this->kirby->impersonate(self::EMAIL, function () use ($photo) {
            $changes = $photo->version('changes');
            $changes->save([...$photo->content()->toArray(), 'text' => 'A quiet street.']);
            $changes->publish();
        });

        $entry = Log::entries()[0];
        $this->assertSame('page.update', $entry['action']);
        $this->assertSame(self::EMAIL, $entry['user']);
        $this->assertSame(['text'], array_column($entry['changes'], 'field'));
    }

    public function testUploadLogsUserAndAutomaticExif(): void
    {
        $this->kirby->impersonate(self::EMAIL, fn() => $this->post->createFile([
            'source' => __DIR__ . '/../fixtures/exif.jpg',
            'filename' => '01.jpg',
            'template' => 'image',
        ]));

        $entries = array_reverse(Log::entries());
        $actions = array_map(fn($e) => [$e['action'], $e['name']], $entries);
        $this->assertContains(['file.create', 'Author'], $actions);
        // The EXIF hook of the blog plugin writes as the user kirby. The Panel
        // shows such an entry as "Automatisch".
        $this->assertContains(['file.update', null], $actions);
    }

    public function testDeleteIsLoggedWithoutLink(): void
    {
        $this->kirby->impersonate(self::EMAIL, fn() => $this->kirby->page('photos/test-photo')->delete(true));

        $entry = Log::entries()[0];
        $this->assertSame('page.delete', $entry['action']);
        $this->assertSame('Test Photo', $entry['title']);
        $this->assertNull($entry['link']);
    }

    public function testPasswordIsNeverLogged(): void
    {
        $user = $this->kirby->user(self::EMAIL);
        $this->kirby->impersonate('kirby', fn() => $user->changePassword('another-password-456'));

        $entry = Log::entries()[0];
        $this->assertSame('user.changePassword', $entry['action']);
        $this->assertSame([], $entry['changes']);
        $log = implode('', array_map('file_get_contents', glob($this->root . '/content/.changelog/*.jsonl')));
        $this->assertStringNotContainsString('another-password-456', $log);
        $this->assertStringNotContainsString('$2y$', $log);
    }

    public function testLogIsStateInContentAndNoPage(): void
    {
        $this->kirby->impersonate(self::EMAIL, fn() => $this->kirby->page('photos/test-photo')->update(['text' => 'Dusk.']));

        $this->assertFileExists($this->root . '/content/.changelog/' . date('Y-m') . '.jsonl');
        $this->assertSame(['photos'], $this->kirby->site()->childrenAndDrafts()->pluck('slug'));
    }

    public function testNewestFirstAndLimit(): void
    {
        $photo = $this->kirby->page('photos/test-photo');
        foreach (['A', 'B', 'C'] as $text) {
            $photo = $this->kirby->impersonate(self::EMAIL, fn() => $photo->update(['text' => $text]));
        }

        $entries = Log::entries(2);
        $this->assertCount(2, $entries);
        $this->assertSame('C', $entries[0]['changes'][0]['new']);
        $this->assertSame('B', $entries[1]['changes'][0]['new']);
    }

    public function testSaveWithoutChangeAddsNoEntry(): void
    {
        $photo = $this->kirby->page('photos/test-photo');
        $before = count(Log::entries());
        $this->kirby->impersonate(self::EMAIL, fn() => $photo->update(['intro' => 'Test intro']));
        $this->assertCount($before, Log::entries());
    }

    public function testListFieldsShowAddedAndRemovedRows(): void
    {
        $trip = $this->post('trips', 'trip', 'test-trip', 'Test Trip');
        $point = fn(string $lat, string $label) => ['label' => $label, 'lat' => $lat, 'lon' => '0.39'];

        $trip = $this->kirby->impersonate(self::EMAIL, fn() => $trip->update(['mappoints' => [$point('50.82', 'Brighton')]]));
        $entry = Log::entries(1)[0];
        $this->assertSame([[
            'field' => 'mappoints',
            'label' => 'Kartenpunkte',
            'old' => '',
            'new' => 'Brighton',
        ]], $entry['changes']);

        $this->kirby->impersonate(self::EMAIL, fn() => $trip->update(['mappoints' => [$point('50.83', 'Hove')]]));
        $entry = Log::entries(1)[0];
        $this->assertSame([['field' => 'mappoints', 'label' => 'Kartenpunkte', 'old' => 'Brighton', 'new' => 'Hove']], $entry['changes']);
    }

    public function testFileReferenceAsIdOrUuidIsNoChange(): void
    {
        $file = $this->upload($this->jpeg(100, 100), '01.jpg');
        $this->kirby->impersonate('kirby', fn() => $this->post->version('latest')->update(['cover' => '- ' . $file->id()]));
        $before = count(Log::entries());

        $this->kirby->impersonate(self::EMAIL, fn() => $this->kirby->page('photos/test-photo')->update(['cover' => [$file->uuid()->toString()]]));

        $this->assertCount($before, Log::entries());
    }

    public function testFileReferenceShowsItsName(): void
    {
        $file = $this->upload($this->jpeg(100, 100), '01.jpg');
        $this->kirby->impersonate(self::EMAIL, fn() => $this->kirby->page('photos/test-photo')->update(['cover' => [$file->uuid()->toString()]]));

        $entry = Log::entries()[0];
        $this->assertSame('Titelbild', $entry['changes'][0]['label']);
        $this->assertSame('Test Photo · 01.jpg', $entry['changes'][0]['new']);
    }

    public function testStatusAndKindAsThePanelNamesThem(): void
    {
        $photo = $this->kirby->page('photos/test-photo');
        $this->kirby->impersonate(self::EMAIL, fn() => $photo->changeStatus('draft'));

        $entry = Log::entries(1)[0];
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
        $about = $this->kirby->impersonate('kirby', fn() => $this->kirby->site()->createChild([
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
            $this->kirby->impersonate(self::EMAIL, fn() => $page->update(['text' => 'Changed.']));
            $entry = Log::entries(1)[0];
            $this->assertSame($page->id(), $entry['id']);
            $this->assertSame($kind, $entry['kind'], $page->id());
        }
    }

    public function testNumberThatFollowsTheDateIsNoEntryOfItsOwn(): void
    {
        // The folder number of a post follows its date. A new date changes the
        // folder number, and the log has one entry, the date change.
        $photo = $this->kirby->page('photos/test-photo');
        $before = count(Log::entries());

        $this->kirby->impersonate(self::EMAIL, fn() => $photo->update(['date' => '2026-11-09 10:00:00']));

        $new = array_slice(Log::entries(), 0, count(Log::entries()) - $before);
        $this->assertSame(['page.update'], array_column($new, 'action'));
        $this->assertSame(['date'], array_column($new[0]['changes'], 'field'));
    }

    public function testSortingPagesWithoutDateNumberIsLogged(): void
    {
        $this->kirby->impersonate('kirby', fn() => $this->kirby->site()->createChild(['slug' => 'first', 'template' => 'default', 'content' => ['title' => 'First']])->changeStatus('listed'));
        $second = $this->kirby->impersonate('kirby', fn() => $this->kirby->site()->createChild(['slug' => 'second', 'template' => 'default', 'content' => ['title' => 'Second']])->changeStatus('listed'));
        $before = count(Log::entries());

        $this->kirby->impersonate(self::EMAIL, fn() => $second->changeSort(1));

        // The sibling that moves down gets an entry too, so the test picks the
        // entry of "second".
        $new = array_slice(Log::entries(), 0, count(Log::entries()) - $before);
        $this->assertSame('page.changeNum', $new[0]['action']);
        $moved = array_values(array_filter($new, fn($e) => $e['action'] === 'page.changeNum' && $e['id'] === 'second'));
        $this->assertCount(1, $moved);
        $this->assertSame(self::EMAIL, $moved[0]['user']);
        $this->assertSame([['field' => 'num', 'label' => 'Reihenfolge', 'old' => '2', 'new' => '1']], $moved[0]['changes']);
    }

    public function testPublishingIsOneEntry(): void
    {
        $photo = $this->kirby->impersonate('kirby', fn() => $this->kirby->page('photos/test-photo')->changeStatus('draft'));
        $before = count(Log::entries());

        $this->kirby->impersonate(self::EMAIL, fn() => $photo->changeStatus('listed'));

        $new = array_slice(Log::entries(), 0, count(Log::entries()) - $before);
        $this->assertSame(['page.changeStatus'], array_column($new, 'action'));
        $this->assertSame(['Entwurf', 'Veröffentlicht'], [$new[0]['changes'][0]['old'], $new[0]['changes'][0]['new']]);
    }


    public function testLoginIsLogged(): void
    {
        $session = $this->kirby->session(['createMode' => 'manual']);
        $this->kirby->user(self::EMAIL)->loginPasswordless($session);
        // Without destroy(), the session writes its file at the end of the
        // run, when the test site is gone.
        $session->destroy();

        $entry = Log::entries()[0];
        $this->assertSame('user.login', $entry['action']);
        $this->assertSame(self::EMAIL, $entry['user']);
        $this->assertSame('Author', $entry['title']);
    }

    public function testFailedLoginIsLoggedWithoutThePassword(): void
    {
        try {
            $this->kirby->auth()->login(self::EMAIL, 'wrong-password-789');
            $this->fail('The login must fail.');
        } catch (\Kirby\Exception\Exception) {
        }

        $entry = Log::entries()[0];
        $this->assertSame('user.login:failed', $entry['action']);
        $this->assertNull($entry['user']);
        $this->assertSame(self::EMAIL, $entry['title']);
        $raw = implode('', array_map('file_get_contents', glob($this->root . '/content/.changelog/*.jsonl')));
        $this->assertStringNotContainsString('wrong-password-789', $raw);
    }

    public function testAttemptsWhileBlockedAddNoEntry(): void
    {
        // track() without the hook counts failed attempts like a login does.
        for ($i = 0; $i < 10; $i++) {
            $this->kirby->auth()->track(self::EMAIL, false);
        }

        Log::failedLogin(self::EMAIL);

        $this->assertNotContains('user.login:failed', array_column(Log::entries(), 'action'));
    }

    /** Failed logins from many addresses have their own limit and push no change out of "Verlauf". */
    public function testFailedLoginsDoNotPushChangesOut(): void
    {
        $photo = $this->kirby->page('photos/test-photo');
        $photo = $this->kirby->impersonate(self::EMAIL, fn() => $photo->update(['intro' => 'First']));
        $this->kirby->impersonate(self::EMAIL, fn() => $photo->update(['intro' => 'Second']));
        for ($i = 0; $i < 5; $i++) {
            Log::failedLogin("guess$i@website.test");
        }

        $entries = Log::entries(2);

        $this->assertSame(['user.login:failed', 'user.login:failed', 'page.update', 'page.update'], array_column($entries, 'action'));
        $this->assertSame(['guess4@website.test', 'guess3@website.test'], array_column(array_slice($entries, 0, 2), 'title'));
    }

    public function testFailedLoginKeepsAtMostTheLengthOfAnAddress(): void
    {
        Log::failedLogin(str_repeat('a', 1000) . '@website.test');

        $this->assertSame(254, mb_strlen(Log::entries()[0]['title']));
    }
}
