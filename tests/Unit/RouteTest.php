<?php

namespace Website\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Website\Assistant\Gpx;
use Website\Assistant\Route;
use Website\Tests\Support\GpxFixture;

/**
 * `Route` and `Gpx`: the OSRM request, simplification, reading a GPX file,
 * and the random cut at both ends of a ride. The cut removes 700 to 1200 m
 * at each end, so a published route does not show where the rides start
 * and end.
 */
final class RouteTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/website-route-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*'));
        rmdir($this->dir);
    }

    private function gpx(string $xml): string
    {
        return GpxFixture::write($this->dir, $xml);
    }

    public function testDistanceAndLength(): void
    {
        $this->assertEqualsWithDelta(1112, Route::distance([12.0, 51.0], [12.0, 51.01]), 1);
        $this->assertEqualsWithDelta(15708, Route::length(GpxFixture::ride()), 30);
    }

    public function testSimplifyKeepsTheShape(): void
    {
        $this->assertSame([[0, 0], [2, 0], [3, 1], [4, 0]], Route::simplify([[0, 0], [1, 0.0001], [2, 0], [3, 1], [4, 0]], 0.0005));
        $this->assertSame([[12.12346, 51.1], [12.2, 51.2]], Route::round([[12.123456, 51.1], [12.1234564, 51.1000001], [12.2, 51.2]]));
    }

    public function testTheCutLiesAtLeastTheGivenDistanceFromBothEnds(): void
    {
        $ride = GpxFixture::ride();
        [$first, $last] = Route::cut($ride, 700, 1200);
        $this->assertGreaterThanOrEqual(700, Route::length(array_slice($ride, 0, $first + 1)));
        $this->assertLessThan(750, Route::length(array_slice($ride, 0, $first + 1)));
        $this->assertGreaterThanOrEqual(1200, Route::length(array_slice($ride, $last)));
        // The cut points also lie that far from both ends in a straight line,
        // because a ride ends where it began.
        foreach ([$ride[0], end($ride)] as $end) {
            $this->assertGreaterThanOrEqual(700, Route::distance($end, $ride[$first]));
            $this->assertGreaterThanOrEqual(1200, Route::distance($end, $ride[$last]));
        }
    }

    public function testAGpxFileIsReadWithItsTimesOnly(): void
    {
        $gpx = Gpx::read($this->gpx(GpxFixture::xml(array_slice(GpxFixture::ride(), 0, 3))));
        $this->assertCount(3, $gpx['points']);
        $this->assertSame([1790841600, 1790841610, 1790841620], $gpx['times']);
        $this->assertSame(['points', 'times'], array_keys($gpx));
    }

    public function testAnUnsafeOrOversizedGpxFileIsRefused(): void
    {
        $point = '<trkpt lat="51.1" lon="12.1"/>';
        $cases = [
            'einen Track' => "<gpx><trk><trkseg>$point</trkseg></trk><trk><trkseg>$point</trkseg></trk></gpx>",
            'ein Segment' => "<gpx><trk><trkseg>$point</trkseg><trkseg>$point</trkseg></trk></gpx>",
            'DOCTYPE' => '<?xml version="1.0"?><!DOCTYPE gpx [<!ENTITY x SYSTEM "file:///etc/passwd">]><gpx><trk><trkseg><trkpt lat="51.1" lon="12.1"><name>&x;</name></trkpt></trkseg></trk></gpx>',
            'kein gültiges XML' => '<gpx><trk><trkpt lat="51" lon="12"></trk>',
            'gültigen Koordinaten' => '<gpx><trk><trkseg><trkpt lat="x" lon="12"/></trkseg></trk></gpx>',
            '100.000 Punkte' => '<gpx><trk><trkseg>' . str_repeat('<trkpt lat="51.1" lon="12.1"/>', 100001) . '</trkseg></trk></gpx>',
            '5 MB' => str_repeat(' ', 5 * 1024 * 1024 + 1),
        ];
        foreach ($cases as $message => $xml) {
            // A failed PHPUnit assertion is a RuntimeException too, so the
            // assertions stay outside the try block.
            $error = null;
            try {
                Gpx::read($this->gpx($xml));
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
            $this->assertNotNull($error, "No error: $message");
            $this->assertStringContainsString($message, $error);
        }
    }

    public function testBothEndsOfARideAreCutByTheirOwnRandomLength(): void
    {
        $ride = GpxFixture::ride();
        $home = GpxFixture::home();
        $heads = [];
        $tails = [];
        for ($run = 0; $run < 20; $run++) {
            $line = Gpx::line($ride, range(0, 3600, 10));
            $heads[] = Route::distance($home, $line['line'][0]);
            $tails[] = Route::distance($home, end($line['line']));
            foreach ($line['line'] as $point) {
                $this->assertGreaterThanOrEqual(Gpx::TRIM_MIN, Route::distance($home, $point));
            }
            $this->assertSame([array_map(fn($v) => round($v, 3), $ride[0]), array_map(fn($v) => round($v, 3), $ride[360])], $line['ends'], 'The ends stay on the server, to 3 decimals.');
        }
        $this->assertGreaterThanOrEqual(Gpx::TRIM_MIN, min([...$heads, ...$tails]));
        $this->assertLessThan(1250, max([...$heads, ...$tails]));
        $this->assertGreaterThan(1, count(array_unique(array_map('round', $heads))), 'The cut changes from ride to ride.');
        $this->assertNotSame(array_map('round', $heads), array_map('round', $tails), 'Start and end are cut differently.');
    }

    public function testAStandstillAtHomeDoesNotShortenTheCut(): void
    {
        // Five minutes of GPS noise at home before and after the ride add up
        // to more than a kilometre of track without leaving home.
        $home = GpxFixture::home();
        $points = [...GpxFixture::standstill(300, 4), ...GpxFixture::ride(), ...GpxFixture::standstill(300, 4)];
        $this->assertGreaterThan(1200, Route::length(GpxFixture::standstill(300, 4)));
        for ($run = 0; $run < 20; $run++) {
            $line = Gpx::line($points, array_fill(0, count($points), null));
            foreach ($line['line'] as $point) {
                $this->assertGreaterThanOrEqual(Gpx::TRIM_MIN, Route::distance($home, $point));
            }
        }
    }

    public function testLapsNearHomeGiveNoRoute(): void
    {
        $laps = GpxFixture::laps(4, 300);
        $this->assertGreaterThan(3000, Route::length($laps));

        $line = Gpx::line($laps, array_fill(0, count($laps), null));

        $this->assertStringContainsString('1.5 km', $line['error']);
        $this->assertArrayNotHasKey('line', $line);
    }

    public function testAShortOrEmptyTrackGivesClaudeAMessage(): void
    {
        $this->assertSame(['error' => 'The GPX file has no track points.'], Gpx::line([], []));
        $short = Gpx::line(GpxFixture::oneWay(4000), []);
        $this->assertStringContainsString('shorter than 3 km', $short['error']);
        $this->assertArrayNotHasKey('line', $short);
    }

    public function testOsrmIsAskedOnceWithItsOwnAgent(): void
    {
        $calls = [];
        $http = function (string $url, string $agent) use (&$calls) {
            $calls[] = [$url, $agent];
            return [200, json_encode(['code' => 'Ok', 'routes' => [['distance' => 12345.6, 'duration' => 900.4, 'geometry' => ['type' => 'LineString', 'coordinates' => [[12.3, 51.3], [12.35, 51.31], [13.4, 52.5]]]]]])];
        };

        $route = Route::osrm([[12.3, 51.3], [13.4, 52.5]], $http);

        $this->assertSame([['https://router.project-osrm.org/route/v1/driving/12.3,51.3;13.4,52.5?overview=full&geometries=geojson', Route::agent()]], $calls);
        $this->assertStringEndsWith('/assistant (Kirby; one request per proposal)', Route::agent());
        $this->assertSame(['line' => [[12.3, 51.3], [12.35, 51.31], [13.4, 52.5]], 'km' => 12.3, 'seconds' => 900], $route);
    }

    public function testOsrmErrorsComeBackAsMessages(): void
    {
        $calls = 0;
        foreach ([[0, ''], [200, '{"code":"NoRoute","message":"Impossible route"}'], [429, '{"code":"TooBig"}']] as [$status, $body]) {
            try {
                Route::osrm([[12.3, 51.3], [13.4, 52.5]], function () use ($status, $body, &$calls) {
                    $calls++;
                    return [$status, $body];
                });
                $this->fail('No error');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('OSRM', $e->getMessage());
            }
        }
        $this->assertSame(3, $calls, 'No retry.');
    }
}
