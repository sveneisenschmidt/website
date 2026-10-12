<?php

namespace Website\Tests\Support;

/**
 * Bike rides as Sven records them: they start and end at home. A ride is
 * a circle around CENTER, one point per degree, 10 seconds apart; its first
 * and last point is home.
 */
final class GpxFixture
{
    public const CENTER = [12.37, 51.34];

    /** Points [lon, lat] of a circle with $radius meters, starting and ending at home. */
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

    /** Home of every ride: its first point. */
    public static function home(): array
    {
        return self::ride()[0];
    }

    /** A GPX 1.1 file with times, an elevation and a device extension per point. */
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
