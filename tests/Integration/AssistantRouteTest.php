<?php

namespace Website\Tests\Integration;

use Kirby\Cms\Page;
use Website\Assistant\Apply;
use Website\Assistant\ChatState;
use Website\Assistant\Content;
use Website\Assistant\Gpx;
use Website\Assistant\Route;
use Website\Assistant\Session;
use Website\Assistant\Uploads;
use Website\Tests\Support\GpxFixture;

/**
 * The route card: stations through OSRM or a GPX upload; replacing the
 * route keeps the old file as state.
 */
final class AssistantRouteTest extends AssistantTestCase
{
    private const OSRM = ['code' => 'Ok', 'routes' => [['distance' => 513400, 'duration' => 21600, 'geometry' => ['type' => 'LineString', 'coordinates' => [[12.2823, 51.1547], [9.4797, 51.3127], [6.1724, 51.3704]]]]]];

    /** @return array<mixed> */
    private function stations(): array
    {
        return [
            ['lat' => 51.1547, 'lon' => 12.2823, 'label' => 'Groitzsch', 'marker' => true],
            ['lat' => 51.3127, 'lon' => 9.4797, 'label' => 'Kassel', 'marker' => false],
            ['lat' => 51.3704, 'lon' => 6.1724, 'label' => 'Venlo', 'marker' => true],
        ];
    }

    /** A trip with an old route: a file, two points, zoom on. */
    private function trip(string $status): Page
    {
        $trip = $this->post('trips', 'trip', 'day-1', 'Day 1', $status);
        $this->kirby->impersonate('kirby', function () use ($trip) {
            file_put_contents($this->root . '/old.json', '{"type":"LineString","coordinates":[[12.1,51.1],[12.2,51.2]]}');
            $file = $trip->createFile(['source' => $this->root . '/old.json', 'filename' => 'route.json', 'template' => 'route']);
            $trip->update(['route' => [$file->uuid()->toString()], 'mappoints' => [['label' => 'Old', 'lat' => '51.1', 'lon' => '12.1']], 'mapzoom' => 'true']);
        });
        return $this->kirby->site()->find('trips')->findPageOrDraft('day-1');
    }

    /** @param array<mixed> $route */
    private function propose(array $route, string $post = 'trips/day-1'): ChatState
    {
        $this->reply('tool_use', $this->tool('t1', 'propose_route', ['post' => $post, 'route' => $route + ['stations' => [], 'gpx' => '', 'markers' => [], 'zoom' => false]]));
        $this->reply('end_turn');
        return $this->chat('Route bitte.');
    }

    public function testStationsGoToOsrmOnceAndTheRouteReplacesTheOldOne(): void
    {
        $this->trip('listed');
        $this->osrm[] = [200, json_encode(self::OSRM)];

        $card = $this->card($this->propose(['stations' => $this->stations()]));

        $this->assertCount(1, $this->osrmCalls);
        $this->assertStringStartsWith('https://router.project-osrm.org/route/v1/driving/12.2823,51.1547;9.4797,51.3127;6.1724,51.3704?', $this->osrmCalls[0]);
        $this->assertSame(['label' => 'Länge', 'before' => '13,1 km', 'after' => '513,4 km · OSRM'], $card['diff'][0]);
        $this->assertSame(['label' => 'Fahrzeit', 'before' => '', 'after' => '6 h 0 min'], $card['diff'][1]);
        $this->assertSame(['label' => 'Marker', 'before' => 'Old', 'after' => 'Groitzsch, Venlo'], $card['diff'][3]);

        Apply::decide($card['id'], 'apply');

        $trip = $this->kirby->site()->find('trips')->findPageOrDraft('day-1');
        $this->assertSame('done', Session::decisions()[$card['id']]['state']);
        $this->assertSame('{"type":"LineString","coordinates":[[12.1,51.1],[12.2,51.2]]}', file_get_contents($trip->file('route.json')->root()), 'The live file stays until Sven saves.');
        $changes = $trip->version('changes')->read();
        $new = Content::fileRef($trip, Content::list($changes['route'])[0]);
        $this->assertStringStartsWith('route-', $new->filename());
        $this->assertSame('route', $new->template());
        $this->assertSame([[12.2823, 51.1547], [9.4797, 51.3127], [6.1724, 51.3704]], Route::line(file_get_contents($new->root())));
        $this->assertSame([['label' => 'Groitzsch', 'lat' => '51.1547', 'lon' => '12.2823'], ['label' => 'Venlo', 'lat' => '51.3704', 'lon' => '6.1724']], Content::list($changes['mappoints']));
        $this->assertSame('false', $changes['mapzoom']);
        $kept = glob($this->root . '/content/.assistant/routes/trips+day-1-*-route.json');
        $this->assertCount(1, $kept);
        $this->assertSame('{"type":"LineString","coordinates":[[12.1,51.1],[12.2,51.2]]}', file_get_contents($kept[0]));
    }

    public function testOnADraftTheRouteFileIsReplacedInPlace(): void
    {
        $uuid = $this->trip('draft')->file('route.json')->uuid()->toString();
        $this->osrm[] = [200, json_encode(self::OSRM)];
        $card = $this->card($this->propose(['stations' => $this->stations(), 'zoom' => true]));

        Apply::decide($card['id'], 'apply');

        $trip = $this->kirby->site()->find('trips')->findPageOrDraft('day-1');
        $this->assertSame($uuid, $trip->file('route.json')->uuid()->toString());
        $this->assertCount(3, Route::line(file_get_contents($trip->file('route.json')->root())));
        $this->assertSame('Groitzsch', $trip->mappoints()->toStructure()->first()->label()->value());
        $this->assertFalse($trip->version('changes')->exists());
        $this->assertCount(1, glob($this->root . '/content/.assistant/routes/*.json'));
    }

    public function testAStaleRouteCardChangesNothing(): void
    {
        $trip = $this->trip('listed');
        $this->osrm[] = [200, json_encode(self::OSRM)];
        $card = $this->card($this->propose(['stations' => $this->stations()]));
        $trip->update(['mapzoom' => 'false']);

        Apply::decide($card['id'], 'apply');

        $this->assertSame('stale', Session::decisions()[$card['id']]['state']);
        $this->assertFalse($this->kirby->site()->find('trips')->findPageOrDraft('day-1')->version('changes')->exists());
        $this->assertSame([], glob($this->root . '/content/.assistant/routes/*') ?: []);
    }

    public function testWithoutAnOsrmRouteThereIsNoCard(): void
    {
        $this->trip('listed');
        $this->osrm[] = [200, '{"code":"NoRoute","message":"Impossible route between points"}'];

        $chat = $this->propose(['stations' => $this->stations()]);

        $this->assertSame([], $chat->proposals);
        $this->assertSame('OSRM found no route: Impossible route between points.', $this->results(1)[0]['content']);
        $this->assertCount(1, $this->osrmCalls);
    }

    public function testRoutesAreForPhotoAndTripOnly(): void
    {
        $this->post('notes', 'note', 'a-note', 'A note');
        $this->propose(['stations' => $this->stations()], 'notes/a-note');
        $this->assertStringContainsString('photo and trip', $this->results(1)[0]['content']);
        $this->assertSame([], $this->osrmCalls);
    }

    public function testTheZoneHoldsAroundTheEndsBeforeTheyWereRounded(): void
    {
        $this->trip('listed');
        $home = GpxFixture::home();
        $id = Uploads::accept(GpxFixture::write($this->root, GpxFixture::xml(GpxFixture::ride())), 'ride.gpx')['id'];
        // 1199 m from home, on the side away from the stored, rounded end.
        $rounded = array_map(fn($v) => round($v, 3), $home);
        $east = ($home[0] - $rounded[0]) * 111320 * cos(deg2rad($home[1]));
        $north = ($home[1] - $rounded[1]) * 111320;
        $norm = sqrt($east ** 2 + $north ** 2);
        $marker = ['lat' => $home[1] + 1199 * $north / $norm / 111320, 'lon' => $home[0] + 1199 * $east / $norm / (111320 * cos(deg2rad($home[1]))), 'label' => 'Near'];
        $this->assertLessThan(1200, Route::distance($home, [$marker['lon'], $marker['lat']]));

        $chat = $this->propose(['gpx' => $id, 'markers' => [$marker]]);

        $this->assertSame([], $chat->proposals);
        $this->assertStringContainsString('closer than 1200 m', $this->results(1)[0]['content']);
    }

    public function testAGpxRouteNeverShowsTheCutEnds(): void
    {
        $this->trip('listed');
        $home = GpxFixture::home();
        $id = Uploads::accept(GpxFixture::write($this->root, GpxFixture::xml(GpxFixture::ride())), 'ride.gpx')['id'];
        $far = ['lat' => GpxFixture::CENTER[1], 'lon' => 2 * GpxFixture::CENTER[0] - $home[0], 'label' => 'Café'];

        $refused = $this->propose(['gpx' => $id, 'markers' => [['lat' => $home[1] + 0.005, 'lon' => $home[0], 'label' => 'Home']]]);
        $card = $this->card($this->propose(['gpx' => $id, 'markers' => [$far]]));
        Apply::decide($card['id'], 'apply');

        $this->assertSame([], $refused->proposals);
        $this->assertStringContainsString('closer than 1200 m', $this->results(1)[0]['content']);
        $this->assertSame([], $this->osrmCalls);
        // Every coordinate pair in every request to Claude lies outside the cut ends.
        preg_match_all('/\[(-?\d+\.\d+),(-?\d+\.\d+)\]/', json_encode($this->requests), $pairs, PREG_SET_ORDER);
        $this->assertGreaterThan(30, count($pairs));
        foreach ($pairs as [, $lon, $lat]) {
            $this->assertGreaterThanOrEqual(Gpx::TRIM_MIN, Route::distance($home, [(float) $lon, (float) $lat]));
        }
        $trip = $this->kirby->site()->find('trips')->findPageOrDraft('day-1');
        $file = Content::fileRef($trip, Content::list($trip->version('changes')->read()['route'])[0]);
        foreach (Route::line(file_get_contents($file->root())) as $point) {
            $this->assertGreaterThanOrEqual(Gpx::TRIM_MIN, Route::distance($home, $point));
        }
        $this->assertNull(Uploads::meta($id), 'The GPX upload goes after the click.');
    }
}
