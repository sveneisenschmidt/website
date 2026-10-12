<?php

namespace Website\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Website\Tests\Support\GpxFixture;

/**
 * route.php: OSRM, simplification, GPX reading and the random cut of both
 * ends (spec, "Tools" and "GPX und Svens Zuhause").
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
        $this->assertEqualsWithDelta(1112, blog_route_distance([12.0, 51.0], [12.0, 51.01]), 1);
        $this->assertEqualsWithDelta(15708, blog_route_length(GpxFixture::ride()), 30);
    }

    public function testSimplifyKeepsTheShape(): void
    {
        $this->assertSame([[0, 0], [2, 0], [3, 1], [4, 0]], blog_route_simplify([[0, 0], [1, 0.0001], [2, 0], [3, 1], [4, 0]], 0.0005));
        $this->assertSame([[12.12346, 51.1], [12.2, 51.2]], blog_route_round([[12.123456, 51.1], [12.1234564, 51.1000001], [12.2, 51.2]]));
    }

    public function testCutWalksAlongTheLine(): void
    {
        $ride = GpxFixture::ride();
        [$first, $last] = blog_route_cut($ride, 700, 1200);
        $this->assertGreaterThanOrEqual(700, blog_route_length(array_slice($ride, 0, $first + 1)));
        $this->assertLessThan(750, blog_route_length(array_slice($ride, 0, $first + 1)));
        $this->assertGreaterThanOrEqual(1200, blog_route_length(array_slice($ride, $last)));
    }

    public function testAGpxFileIsReadWithItsTimesOnly(): void
    {
        $gpx = blog_gpx_read($this->gpx(GpxFixture::xml(array_slice(GpxFixture::ride(), 0, 3))));
        $this->assertCount(3, $gpx['points']);
        $this->assertSame([1790841600, 1790841610, 1790841620], $gpx['times']);
        $this->assertSame(['points', 'times'], array_keys($gpx));
    }

    public function testAGpxFileIsReadHardened(): void
    {
        $cases = [
            'DOCTYPE' => '<?xml version="1.0"?><!DOCTYPE gpx [<!ENTITY x SYSTEM "file:///etc/passwd">]><gpx><trk><trkseg><trkpt lat="51.1" lon="12.1"><name>&x;</name></trkpt></trkseg></trk></gpx>',
            'kein gültiges XML' => '<gpx><trk><trkpt lat="51" lon="12"></trk>',
            'gültigen Koordinaten' => '<gpx><trk><trkseg><trkpt lat="x" lon="12"/></trkseg></trk></gpx>',
            '100.000 Punkte' => '<gpx><trk><trkseg>' . str_repeat('<trkpt lat="51.1" lon="12.1"/>', 100001) . '</trkseg></trk></gpx>',
            '5 MB' => str_repeat(' ', 5 * 1024 * 1024 + 1),
        ];
        foreach ($cases as $message => $xml) {
            try {
                blog_gpx_read($this->gpx($xml));
                $this->fail("No error: $message");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testBothEndsOfARideAreCutByTheirOwnRandomLength(): void
    {
        $ride = GpxFixture::ride();
        $home = GpxFixture::home();
        $heads = [];
        $tails = [];
        for ($run = 0; $run < 20; $run++) {
            $line = blog_gpx_line($ride, range(0, 3600, 10));
            $heads[] = blog_route_distance($home, $line['line'][0]);
            $tails[] = blog_route_distance($home, end($line['line']));
            foreach ($line['line'] as $point) {
                $this->assertGreaterThan(650, blog_route_distance($home, $point));
            }
            $this->assertSame([$ride[0], $ride[360]], $line['ends']);
        }
        $this->assertGreaterThanOrEqual(650, min([...$heads, ...$tails]));
        $this->assertLessThan(1250, max([...$heads, ...$tails]));
        $this->assertGreaterThan(1, count(array_unique(array_map('round', $heads))), 'The cut changes from ride to ride.');
        $this->assertNotSame(array_map('round', $heads), array_map('round', $tails), 'Start and end are cut differently.');
    }

    public function testAShortOrEmptyTrackGivesClaudeAMessage(): void
    {
        $this->assertSame(['error' => 'The GPX file has no track points.'], blog_gpx_line([], []));
        $short = blog_gpx_line(GpxFixture::ride(500), []);
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

        $route = blog_route_osrm([[12.3, 51.3], [13.4, 52.5]], $http);

        $this->assertSame([['https://router.project-osrm.org/route/v1/driving/12.3,51.3;13.4,52.5?overview=full&geometries=geojson', BLOG_ROUTE_AGENT]], $calls);
        $this->assertSame(['line' => [[12.3, 51.3], [12.35, 51.31], [13.4, 52.5]], 'km' => 12.3, 'seconds' => 900], $route);
    }

    public function testOsrmErrorsComeBackAsMessages(): void
    {
        $calls = 0;
        foreach ([[0, ''], [200, '{"code":"NoRoute","message":"Impossible route"}'], [429, '{"code":"TooBig"}']] as [$status, $body]) {
            try {
                blog_route_osrm([[12.3, 51.3], [13.4, 52.5]], function () use ($status, $body, &$calls) {
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
