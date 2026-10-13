<?php

namespace Website\Assistant;

use RuntimeException;
use XMLReader;

/**
 * GPX files of bike rides: read hardened, both ends cut, simplified. Rides
 * start and end at home; Claude never sees the cut ends.
 */
final class Gpx
{
    /** Douglas-Peucker tolerance in degrees for tracks. */
    public const EPSILON = 0.0002;

    /** GPX files: at most 5 MB and 100,000 points. */
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_POINTS = 100000;

    /** Each end of a ride loses a random length between these, in meters. */
    public const TRIM_MIN = 700;
    public const TRIM_MAX = 1200;

    /** No marker closer than this to an end of the ride before trimming, in meters. */
    public const ZONE = 1200;

    /**
     * The uncut ends stay on the server for the marker check, to 3 decimals
     * only. Rounding moves a point by at most 0.0005° in each direction, 56 m
     * at most, 79 m together: the check adds that.
     */
    public const ENDS_DECIMALS = 3;
    public const ZONE_SLACK = 80;

    /** A ride that never gets farther than this from its ends gives no route, in meters. */
    public const NEAR = 1500;

    /** A ride shorter than this after trimming gives no route, in meters. */
    public const MIN_LENGTH = 3000;

    /**
     * Track points and times of a GPX file, read without DOCTYPE, network or
     * entities. Throws RuntimeException with the message the Panel shows.
     * Names, devices and extensions are never read.
     *
     * @return array{points: list<array{float, float}>, times: list<?int>}
     */
    public static function read(string $path): array
    {
        if (!is_file($path) || filesize($path) > self::MAX_BYTES) {
            throw new RuntimeException('Die GPX-Datei ist größer als ' . intdiv(self::MAX_BYTES, 1024 * 1024) . ' MB.');
        }
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $points = [];
        $times = [];
        $inPoint = false;
        $parts = ['trk' => 0, 'trkseg' => 0];
        try {
            $reader = XMLReader::fromUri($path, null, LIBXML_NONET);
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::DOC_TYPE) {
                    throw new RuntimeException('Die GPX-Datei hat eine DOCTYPE-Angabe.');
                }
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'trkpt') {
                    $lat = $reader->getAttribute('lat');
                    $lon = $reader->getAttribute('lon');
                    if (!is_numeric($lat) || !is_numeric($lon) || abs((float) $lat) > 90 || abs((float) $lon) > 180) {
                        throw new RuntimeException('Ein Punkt der GPX-Datei hat keine gültigen Koordinaten.');
                    }
                    if (count($points) === self::MAX_POINTS) {
                        throw new RuntimeException('Die GPX-Datei hat mehr als ' . number_format(self::MAX_POINTS, 0, ',', '.') . ' Punkte.');
                    }
                    $points[] = [(float) $lon, (float) $lat];
                    $times[] = null;
                    $inPoint = !$reader->isEmptyElement;
                } elseif ($inPoint && $reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'time') {
                    $time = strtotime($reader->readString());
                    $times[array_key_last($times)] = $time === false ? null : $time;
                } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'trkpt') {
                    $inPoint = false;
                } elseif ($reader->nodeType === XMLReader::ELEMENT && isset($parts[$reader->localName]) && ++$parts[$reader->localName] > 1) {
                    // Each track and segment would need its own cut: one ride per file.
                    throw new RuntimeException('Die GPX-Datei hat mehr als einen Track oder mehr als ein Segment. Eine Fahrt pro Datei.');
                }
            }
            if (libxml_get_errors() !== []) {
                throw new RuntimeException('Die GPX-Datei ist kein gültiges XML.');
            }
            $reader->close();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return ['points' => $points, 'times' => $times];
    }

    /**
     * The line Claude may see of a ride: both ends cut by their own random
     * length, simplified. Rides start and end at home: a fixed length would
     * put all ends on a circle around it. The lengths are not kept. "ends"
     * holds the uncut ends, rounded, for the marker check; it stays on the
     * server. A track without points, one that never gets farther than 1.5 km
     * from its ends, or one shorter than 3 km after the cut gives "error",
     * the message Claude gets.
     *
     * @param list<array<int, float|int>> $points
     * @param array<int, ?int> $times
     * @return array<string, mixed>
     */
    public static function line(array $points, array $times): array
    {
        if (count($points) < 2) {
            return ['error' => 'The GPX file has no track points.'];
        }
        $ends = [$points[0], $points[count($points) - 1]];
        // Laps around the block show the block, cut or not.
        $far = false;
        foreach ($points as $point) {
            if (Route::distance($ends[0], $point) > self::NEAR && Route::distance($ends[1], $point) > self::NEAR) {
                $far = true;
                break;
            }
        }
        if (!$far) {
            return ['error' => 'The GPX track never gets farther than ' . (self::NEAR / 1000) . ' km from its start and end. It gives no route.'];
        }
        [$first, $last] = Route::cut($points, random_int(self::TRIM_MIN, self::TRIM_MAX), random_int(self::TRIM_MIN, self::TRIM_MAX));
        $kept = array_slice($points, $first, $last - $first + 1);
        $length = Route::length($kept);
        if (count($kept) < 2 || $length < self::MIN_LENGTH) {
            return ['error' => 'The GPX track is shorter than ' . (self::MIN_LENGTH / 1000) . ' km after cutting its ends.'];
        }
        return [
            'line' => Route::round(Route::simplify($kept, self::EPSILON)),
            'km' => round($length / 1000, 1),
            'seconds' => isset($times[$first], $times[$last]) ? max(0, $times[$last] - $times[$first]) : null,
            'ends' => array_map(fn($end) => [round((float) $end[0], self::ENDS_DECIMALS), round((float) $end[1], self::ENDS_DECIMALS)], $ends),
        ];
    }
}
