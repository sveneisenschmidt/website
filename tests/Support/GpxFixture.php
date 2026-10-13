<?php

namespace Website\Tests\Support;

/**
 * Bike rides as the author records them: they start and end at home. A
 * ride is a circle around CENTER with one point per degree, 10 seconds
 * apart. Its first and last point is home.
 */
final class GpxFixture
{
    public const CENTER = [12.37, 51.34];

    /**
     * Points [lon, lat] of a circle with $radius meters, starting and ending at home.
     *
     * @return array<mixed>
     */
    public static function ride(float $radius = 2500): array
    {
        $dLat = $radius / 111320;
        $dLon = $radius / (111320 * cos(deg2rad(self::CENTER[1])));
        $points = [];
        for ($degree = 0; $degree <= 360; $degree++) {
            $angle = deg2rad($degree);
            $points[] = [self::CENTER[0] + $dLon * cos($angle), self::CENTER[1] + $dLat * sin($angle)];
        }
        return $points;
    }

    /**
     * Home of every ride: its first point.
     *
     * @return array<mixed>
     */
    public static function home(): array
    {
        return self::ride()[0];
    }

    /**
     * Converts meters to the east and north into degrees of longitude and
     * latitude at home.
     *
     * @return array<mixed>
     */
    private static function degrees(float $east, float $north): array
    {
        return [$east / (111320 * cos(deg2rad(self::CENTER[1]))), $north / 111320];
    }

    /**
     * A standstill at home before or after a ride: one point per second for
     * $seconds, each up to $meters off, spread like GPS noise but the same
     * in every run.
     *
     * @return array<mixed>
     */
    public static function standstill(int $seconds, float $meters): array
    {
        [$lon, $lat] = self::home();
        $points = [];
        for ($i = 0; $i < $seconds; $i++) {
            [$dLon, $dLat] = self::degrees($meters * sin($i * 1.7), $meters * cos($i * 2.3));
            $points[] = [$lon + $dLon, $lat + $dLat];
        }
        return $points;
    }

    /**
     * A ride from home $meters to the east, a point every 20 m: it ends elsewhere.
     *
     * @return array<mixed>
     */
    public static function oneWay(float $meters): array
    {
        [$lon, $lat] = self::home();
        $points = [];
        for ($walked = 0; $walked <= $meters; $walked += 20) {
            $points[] = [$lon + self::degrees($walked, 0)[0], $lat];
        }
        return $points;
    }

    /**
     * $laps rounds of a circle with $radius meters that starts and ends at home.
     *
     * @return array<mixed>
     */
    public static function laps(int $laps, float $radius): array
    {
        [$lon, $lat] = self::home();
        [$dLon, $dLat] = self::degrees($radius, $radius);
        $points = [];
        for ($degree = 0; $degree < 360 * $laps; $degree += 3) {
            $angle = deg2rad($degree);
            $points[] = [$lon - $dLon + $dLon * cos($angle), $lat + $dLat * sin($angle)];
        }
        $points[] = [$lon, $lat];
        return $points;
    }

    /**
     * A GPX 1.1 file with times, an elevation and a device extension per point.
     *
     * @param array<mixed> $points
     */
    public static function xml(array $points): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<gpx version="1.1" creator="Test Device" xmlns="http://www.topografix.com/GPX/1/1"><metadata><name>Morning ride</name></metadata><trk><trkseg>' . "\n";
        foreach ($points as $i => [$lon, $lat]) {
            $xml .= sprintf('<trkpt lat="%.7F" lon="%.7F"><ele>120</ele><time>%s</time><extensions><hr>120</hr></extensions></trkpt>', $lat, $lon, gmdate('Y-m-d\TH:i:s\Z', 1790841600 + 10 * $i)) . "\n";
        }
        return $xml . '</trkseg></trk></gpx>' . "\n";
    }

    /** Writes $xml to a new file below $dir and returns its path. */
    public static function write(string $dir, string $xml, string $name = 'ride.gpx'): string
    {
        $path = $dir . '/' . $name;
        file_put_contents($path, $xml);
        return $path;
    }
}
