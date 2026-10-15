<?php

namespace Website\Tests\Integration;

use Kirby\Cms\File;
use RuntimeException;
use Website\Assistant\Apply;
use Website\Assistant\Chat;
use Website\Assistant\ChatState;
use Website\Assistant\Content;
use Website\Assistant\Route;
use Website\Assistant\Session;
use Website\Assistant\Tools;
use Website\Assistant\Uploads;
use Website\Blog\Exif;
use Website\Blog\Types;

/**
 * The card "Neuer Entwurf" creates a draft with its fields, photos and route
 * in one click. When one step fails, nothing changes.
 */
final class AssistantPostTest extends AssistantTestCase
{
    /**
     * Maps each field of the post blueprints to the input of propose_post
     * that fills it. A new blueprint field fails
     * testTheToolCoversThePostBlueprints until propose_post fills it and this
     * list names it.
     */
    private const POST_FIELDS = [
        'intro' => 'intro', 'text' => 'text', 'date' => 'date', 'cover' => 'cover', 'pick' => 'pick',
        'trip' => 'trip', 'route' => 'route', 'mappoints' => 'route', 'mapzoom' => 'route',
    ];

    /** While true, the upload of "broken.jpg" fails, as a full disk would. */
    public bool $breakUploads = true;

    /** @var list<bool>|null Once a test sets it to [], each new file adds whether a request held the lock of the session meanwhile. */
    public ?array $lockedOnCreate = null;

    protected function setUp(): void
    {
        // Kirby binds a hook closure to the app, so $this in it is the app.
        // The closure reaches the test through $test.
        $test = $this;
        $this->props['hooks'] = [
            'file.create:before' => function (File $file) use ($test) {
                if ($test->breakUploads && $file->filename() === 'broken.jpg') {
                    throw new RuntimeException('Test: the upload fails');
                }
            },
            'file.create:after' => function () use ($test) {
                if ($test->lockedOnCreate !== null) {
                    $test->lockedOnCreate[] = $test->locked();
                }
            },
        ];
        parent::setUp();
    }

    /**
     * Returns a complete input of propose_post. The keys of $input replace
     * the defaults.
     *
     * @param array<mixed> $input
     * @return array<mixed>
     */
    private function input(array $input = []): array
    {
        return $input + [
            'type' => 'photo',
            'slug' => 'aika',
            'date' => '2026-10-01 20:40:00',
            'title' => 'Aika',
            'intro' => 'It was dark.',
            'text' => '(image: aika.jpg)',
            'pick' => false,
            'trip' => '',
            'photos' => [],
            'cover' => '',
            'route' => ['stations' => [], 'gpx' => '', 'markers' => [], 'zoom' => false],
        ];
    }

    /** @param array<mixed> $input */
    private function propose(array $input): ChatState
    {
        $this->reply('tool_use', $this->tool('t1', 'propose_post', $input));
        $this->reply('end_turn');
        return $this->chat('Leg den Beitrag an.');
    }

    private function error(): string
    {
        return $this->results(count($this->requests) - 1)[0]['content'];
    }

    /** sessionLocked() for the hook, which runs bound to the app. */
    public function locked(): bool
    {
        return $this->sessionLocked();
    }

    /**
     * The draft renders the thumbnails of its photos. Meanwhile the session
     * is free, so other requests of the same browser do not wait.
     */
    public function testTheSessionIsFreeWhileTheDraftIsCreated(): void
    {
        $id = Uploads::accept($this->jpeg(40, 30), 'IMG_1.JPG')['id'];
        $card = $this->card($this->propose($this->input(['text' => '', 'photos' => [
            ['upload' => $id, 'filename' => 'aika.jpg', 'alt' => '', 'caption' => '', 'photographer' => ''],
        ]])));
        $this->assertTrue($this->sessionLocked(), 'The chat holds the lock before the click.');
        $this->lockedOnCreate = [];

        Apply::decide($card['id'], 'apply');

        $this->assertSame([false], $this->lockedOnCreate);
        $this->assertSame('done', Session::decisions()[$card['id']]['state']);
    }

    public function testADraftComesWithPhotosAndRouteInOneClick(): void
    {
        $a = Uploads::accept($this->gpsJpeg(1, 'a.jpg'), 'IMG_1.JPG')['id'];
        $b = Uploads::accept($this->jpeg(40, 30), 'IMG_2.JPG')['id'];
        $this->osrm[] = [200, json_encode(['code' => 'Ok', 'routes' => [['distance' => 1200, 'duration' => 300, 'geometry' => ['coordinates' => [[12.3, 51.3], [12.31, 51.31]]]]]])];
        $chat = $this->propose($this->input([
            'text' => "(image: aika.jpg)\n\n(map:)\n\n(image: dog.jpg)",
            'photos' => [
                ['upload' => $a, 'filename' => 'aika.jpg', 'alt' => 'Aika at night', 'caption' => 'Dark', 'photographer' => 'Nadine'],
                ['upload' => $b, 'filename' => 'dog.jpg', 'alt' => 'A dog', 'caption' => '', 'photographer' => ''],
            ],
            'cover' => 'dog.jpg',
            'pick' => true,
            'route' => ['stations' => [['lat' => 51.3, 'lon' => 12.3, 'label' => 'Start', 'marker' => true], ['lat' => 51.31, 'lon' => 12.31, 'label' => 'End', 'marker' => true]], 'gpx' => '', 'markers' => [], 'zoom' => true],
        ]));
        $card = $this->card($chat);
        $this->assertSame('Neuer Entwurf · Foto', $card['title']);
        $this->assertContains(['label' => 'URL-Anhang', 'value' => 'aika'], $card['rows']);
        $this->assertContains(['label' => 'Marker', 'value' => 'Start, End'], $card['rows']);
        $view = array_values(array_filter(Chat::view(Session::load())['items'], fn($i) => $i['type'] === 'card'));
        $this->assertStringStartsWith('data:image/jpeg;base64,', $view[0]['proposal']['photos'][0]['thumb']);
        $this->assertNull(Content::slugTaken('aika'), 'Nothing exists before the click.');

        Apply::decide($card['id'], 'apply');

        $draft = $this->kirby->site()->find('photos')->draft('aika');
        $this->assertSame('draft', $draft->status());
        $this->assertSame(['Aika', '2026-10-01 20:40:00', 'It was dark.', 'true'], [$draft->title()->value(), $draft->date()->value(), $draft->intro()->value(), $draft->pick()->value()]);
        $aika = $draft->file('aika.jpg');
        $this->assertSame(['Aika at night', 'Dark', 'Nadine', 'GPS-CAM'], [$aika->alt()->value(), $aika->caption()->value(), $aika->photographer()->value(), $aika->camera()->value()]);
        $this->assertArrayNotHasKey('GPS', exif_read_data($aika->root(), null, true) ?: []);
        $this->assertSame('dog.jpg', $draft->cover()->toFile()->filename());
        $this->assertSame('route', $draft->route()->toFile()->template());
        $this->assertSame([[12.3, 51.3], [12.31, 51.31]], Route::line(file_get_contents($draft->route()->toFile()->root())));
        $this->assertSame(2, $draft->mappoints()->toStructure()->count());
        $this->assertSame('true', $draft->mapzoom()->value());
        $this->assertNull(Uploads::meta($a)['path'], 'The uploads go after success.');
        $this->assertStringContainsString('New draft: photos/aika (page://', $this->kirby->session()->get('blog.assistant.notes')[0]);
    }

    public function testTheSlugMustBeFreeInAllSections(): void
    {
        $this->post('notes', 'note', 'aika', 'Aika', 'draft');

        $chat = $this->propose($this->input(['text' => '']));

        $this->assertSame([], $chat->proposals);
        $this->assertSame('The slug aika is taken by notes/aika. The URL must be unique.', $this->error());
    }

    public function testTheDateMustNotLieInTheFuture(): void
    {
        $tomorrow = (new \DateTimeImmutable('tomorrow', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i:s');
        $this->propose($this->input(['date' => $tomorrow, 'text' => '']));
        $this->assertStringContainsString('future', $this->error());
        $this->propose($this->input(['date' => '2026-10-01T20:40', 'text' => '']));
        $this->assertStringContainsString('YYYY-MM-DD HH:MM:SS', $this->error());
    }

    public function testFileNamesAndUploadsAreChecked(): void
    {
        $id = Uploads::accept($this->jpeg(8, 8), 'IMG_1.JPG')['id'];
        $photo = ['upload' => $id, 'filename' => 'IMG 1.JPG', 'alt' => '', 'caption' => '', 'photographer' => ''];
        $this->propose($this->input(['text' => '', 'photos' => [$photo]]));
        $this->assertStringContainsString('as in img_1.jpg', $this->error());
        $this->propose($this->input(['text' => '', 'photos' => [['filename' => 'x.jpg'] + $photo, ['filename' => 'x.jpg'] + $photo]]));
        $this->assertStringContainsString('twice', $this->error());
        $this->propose($this->input(['text' => '', 'photos' => [['upload' => 'u0000000000000000'] + $photo]]));
        $this->assertStringContainsString('No image upload', $this->error());

        $card = $this->card($this->propose($this->input(['text' => '', 'photos' => [['filename' => 'x.jpg'] + $photo]])));
        $this->propose($this->input(['slug' => 'other', 'text' => '', 'photos' => [['filename' => 'x.jpg'] + $photo]]));

        $this->assertStringContainsString('another open card', $this->error());
        $this->assertSame([$id], $card['uploads']);
    }

    public function testAllOrNothing(): void
    {
        $this->expectErrorLog();
        $ok = Uploads::accept($this->jpeg(8, 8, 'ok.jpg'), 'ok.jpg')['id'];
        $broken = Uploads::accept($this->jpeg(8, 8, 'broken.jpg'), 'broken.jpg')['id'];
        $card = $this->card($this->propose($this->input(['text' => '', 'photos' => [
            ['upload' => $ok, 'filename' => 'ok.jpg', 'alt' => '', 'caption' => '', 'photographer' => ''],
            ['upload' => $broken, 'filename' => 'broken.jpg', 'alt' => '', 'caption' => '', 'photographer' => ''],
        ]])));

        Apply::decide($card['id'], 'apply');

        $this->assertSame('failed', Session::decisions()[$card['id']]['state']);
        $this->assertNull(Content::slugTaken('aika'), 'The draft is deleted again.');
        $this->assertNotNull(Uploads::meta($ok)['path'], 'The uploads stay.');
        $this->assertStringContainsString('failed, nothing changed', $this->kirby->session()->get('blog.assistant.notes')[0]);

        $this->breakUploads = false;
        Apply::decide($card['id'], 'apply');

        $this->assertSame('done', Session::decisions()[$card['id']]['state']);
        $this->assertCount(2, $this->kirby->site()->find('photos')->draft('aika')->files());
    }

    public function testToolsDoNotCheckStyle(): void
    {
        $chat = $this->propose($this->input(['slug' => 'x', 'title' => 'WOW!!! 🚲', 'date' => '2001-01-01 03:17:42', 'intro' => '', 'text' => '', 'pick' => true]));

        $this->assertSame('post', $this->card($chat)['kind']);
    }

    public function testTheToolCoversThePostBlueprints(): void
    {
        foreach (Types::templates() as $type) {
            $page = $this->post(Types::ALL[$type]['section'], $type, 'model-' . $type, 'Model');
            $this->assertSame([], array_diff(array_keys($page->blueprint()->fields()), array_keys(self::POST_FIELDS)), $type);
        }
        $file = $this->upload(__DIR__ . '/../fixtures/exif.jpg', 'a.jpg');
        $this->assertEqualsCanonicalizing([...Tools::IMAGE_TEXTS, ...Exif::FIELDS], array_keys($file->blueprint()->fields()));
    }
}
