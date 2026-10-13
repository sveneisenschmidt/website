<?php

namespace Website\Tests\Integration;

use Kirby\Cms\File;
use RuntimeException;

/**
 * The card "Neuer Entwurf": fields, photos and route in one click, all or
 * nothing.
 */
final class AssistantPostTest extends AssistantTestCase
{
    /**
     * The fields of the post blueprints and what fills them in propose_post.
     * A field missing here fails testTheToolCoversThePostBlueprints: the tool must learn it.
     */
    private const POST_FIELDS = [
        'intro' => 'intro', 'text' => 'text', 'date' => 'date', 'cover' => 'cover', 'pick' => 'pick',
        'trip' => 'trip', 'route' => 'route', 'mappoints' => 'route', 'mapzoom' => 'route',
    ];

    /** While true, the upload of "broken.jpg" fails, as a full disk would. */
    public bool $breakUploads = true;

    protected function setUp(): void
    {
        // Kirby binds hooks to the app: the test comes in as $test.
        $test = $this;
        $this->props['hooks'] = ['file.create:before' => function (File $file) use ($test) {
            if ($test->breakUploads && $file->filename() === 'broken.jpg') {
                throw new RuntimeException('Test: the upload fails');
            }
        }];
        parent::setUp();
    }

    /** A complete input of propose_post; $input overrides. */
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

    private function propose(array $input): array
    {
        $this->reply('tool_use', $this->tool('t1', 'propose_post', $input));
        $this->reply('end_turn');
        return $this->chat('Leg den Beitrag an.');
    }

    private function error(): string
    {
        return $this->results(count($this->requests) - 1)[0]['content'];
    }

    public function testADraftComesWithPhotosAndRouteInOneClick(): void
    {
        $a = blog_uploads_accept($this->gpsJpeg(1, 'a.jpg'), 'IMG_1.JPG')['id'];
        $b = blog_uploads_accept($this->jpeg(40, 30), 'IMG_2.JPG')['id'];
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
        $view = array_values(array_filter(blog_assistant_view(blog_assistant_load())['items'], fn($i) => $i['type'] === 'card'));
        $this->assertStringStartsWith('data:image/jpeg;base64,', $view[0]['proposal']['photos'][0]['thumb']);
        $this->assertNull(blog_assistant_slug_taken('aika'), 'Nothing exists before the click.');

        blog_assistant_decide($card['id'], 'apply');

        $draft = $this->kirby->site()->find('photos')->draft('aika');
        $this->assertSame('draft', $draft->status());
        $this->assertSame(['Aika', '2026-10-01 20:40:00', 'It was dark.', 'true'], [$draft->title()->value(), $draft->date()->value(), $draft->intro()->value(), $draft->pick()->value()]);
        $aika = $draft->file('aika.jpg');
        $this->assertSame(['Aika at night', 'Dark', 'Nadine', 'GPS-CAM'], [$aika->alt()->value(), $aika->caption()->value(), $aika->photographer()->value(), $aika->camera()->value()]);
        $this->assertArrayNotHasKey('GPS', exif_read_data($aika->root(), null, true) ?: []);
        $this->assertSame('dog.jpg', $draft->cover()->toFile()->filename());
        $this->assertSame('route', $draft->route()->toFile()->template());
        $this->assertSame([[12.3, 51.3], [12.31, 51.31]], blog_route_line(file_get_contents($draft->route()->toFile()->root())));
        $this->assertSame(2, $draft->mappoints()->toStructure()->count());
        $this->assertSame('true', $draft->mapzoom()->value());
        $this->assertNull(blog_uploads_meta($a)['path'], 'The uploads go after success.');
        $this->assertStringContainsString('New draft: photos/aika (page://', $this->kirby->session()->get('blog.assistant.notes')[0]);
    }

    public function testTheSlugMustBeFreeInAllSections(): void
    {
        $this->post('notes', 'note', 'aika', 'Aika', 'draft');

        $chat = $this->propose($this->input(['text' => '']));

        $this->assertSame([], $chat['proposals']);
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
        $id = blog_uploads_accept($this->jpeg(8, 8), 'IMG_1.JPG')['id'];
        $photo = ['upload' => $id, 'filename' => 'IMG 1.JPG', 'alt' => '', 'caption' => '', 'photographer' => ''];
        $this->propose($this->input(['text' => '', 'photos' => [$photo]]));
        $this->assertStringContainsString('as in img_1.jpg', $this->error());
        $this->propose($this->input(['text' => '', 'photos' => [['filename' => 'x.jpg'] + $photo, ['filename' => 'x.jpg'] + $photo]]));
        $this->assertStringContainsString('twice', $this->error());
        $this->propose($this->input(['text' => '', 'photos' => [['upload' => 'u0000000000000000'] + $photo]]));
        $this->assertStringContainsString('No image upload', $this->error());
        $this->propose($this->input(['text' => '(image: other.jpg)', 'photos' => [['filename' => 'x.jpg'] + $photo]]));
        $this->assertStringContainsString('names no image', $this->error());

        $card = $this->card($this->propose($this->input(['text' => '', 'photos' => [['filename' => 'x.jpg'] + $photo]])));
        $this->propose($this->input(['slug' => 'other', 'text' => '', 'photos' => [['filename' => 'x.jpg'] + $photo]]));

        $this->assertStringContainsString('another open card', $this->error());
        $this->assertSame([$id], $card['uploads']);
    }

    public function testAllOrNothing(): void
    {
        $this->expectErrorLog();
        $ok = blog_uploads_accept($this->jpeg(8, 8, 'ok.jpg'), 'ok.jpg')['id'];
        $broken = blog_uploads_accept($this->jpeg(8, 8, 'broken.jpg'), 'broken.jpg')['id'];
        $card = $this->card($this->propose($this->input(['text' => '', 'photos' => [
            ['upload' => $ok, 'filename' => 'ok.jpg', 'alt' => '', 'caption' => '', 'photographer' => ''],
            ['upload' => $broken, 'filename' => 'broken.jpg', 'alt' => '', 'caption' => '', 'photographer' => ''],
        ]])));

        blog_assistant_decide($card['id'], 'apply');

        $this->assertSame('failed', blog_assistant_decisions()[$card['id']]['state']);
        $this->assertNull(blog_assistant_slug_taken('aika'), 'The draft went again.');
        $this->assertNotNull(blog_uploads_meta($ok)['path'], 'The uploads stay.');
        $this->assertStringContainsString('failed, nothing changed', $this->kirby->session()->get('blog.assistant.notes')[0]);

        $this->breakUploads = false;
        blog_assistant_decide($card['id'], 'apply');

        $this->assertSame('done', blog_assistant_decisions()[$card['id']]['state']);
        $this->assertCount(2, $this->kirby->site()->find('photos')->draft('aika')->files());
    }

    public function testToolsJudgeNoStyle(): void
    {
        $chat = $this->propose($this->input(['slug' => 'x', 'title' => 'WOW!!! 🚲', 'date' => '2001-01-01 03:17:42', 'intro' => '', 'text' => '', 'pick' => true]));

        $this->assertSame('post', $this->card($chat)['kind']);
    }

    public function testTheToolCoversThePostBlueprints(): void
    {
        foreach (array_keys(BLOG_TYPES) as $type) {
            $page = $this->post(BLOG_TYPES[$type]['section'], $type, 'model-' . $type, 'Model');
            $this->assertSame([], array_diff(array_keys($page->blueprint()->fields()), array_keys(self::POST_FIELDS)), $type);
        }
        $file = $this->upload(__DIR__ . '/../fixtures/exif.jpg', 'a.jpg');
        $this->assertEqualsCanonicalizing([...BLOG_ASSISTANT_IMAGE_TEXTS, ...BLOG_EXIF_FIELDS], array_keys($file->blueprint()->fields()));
    }
}
