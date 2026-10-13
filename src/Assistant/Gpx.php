<?php

namespace Website\Assistant;

use RuntimeException;
use XMLReader;

/**
 * Reads the GPX file of a bike ride and turns it into a line that Claude may
 * see. Rides start and end at home, so both ends are cut off before Claude
 * gets the line. The reader refuses what an XML attack needs: a DOCTYPE,
 * network access and entities.
 */
final class Gpx
{
    /** Tolerance of `Route::simplify()` for tracks in degrees, about 22 m. */
    public const EPSILON = 0.0002;

    /** GPX files: at most 5 MB and 100,000 points. */
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_POINTS = 100000;

    /** Each end of a ride loses a random length between these, in meters. */
    public const TRIM_MIN = 700;
    public const TRIM_MAX = 1200;

    /** No marker may lie closer than this to an uncut end of the ride, in meters. */
    public const ZONE = 1200;

    /**
     * The uncut ends stay on the server for the marker check, rounded to 3
     * decimals. Rounding moves a point by at most 0.0005° on each axis: up to
     * 56 m on one axis and 79 m on the diagonal. `ZONE_SLACK` adds that
     * distance to `ZONE`.
     */
    public const ENDS_DECIMALS = 3;
    public const ZONE_SLACK = 80;

    /** A ride that never gets farther than this from its ends gives no route, in meters. */
    public const NEAR = 1500;

    /** A ride shorter than this after trimming gives no route, in meters. */
    public const MIN_LENGTH = 3000;

    /**
     * Reads the track points of a GPX file and their times. A DOCTYPE is
     * refused, so the file cannot define entities, and LIBXML_NONET blocks
     * network access. Names, devices and extensions are never read. Throws
     * RuntimeException with the German message that the Panel shows.
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
                    // Each track and each segment would need its own cut ends,
                    // so a file may contain one ride only.
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
     * The line of a ride that Claude may see: both ends cut by their own
     * random length, then simplified. Rides start and end at home. A fixed
     * length would put all cut ends on a circle, and its center would show
     * where home is. The cut lengths are not stored. "ends" holds the uncut ends, rounded, for the
     * marker check; it stays on the server. A track without points, one that
     * never gets farther than `NEAR` from its ends, or one shorter than
     * `MIN_LENGTH` after the cut gives "error" instead, the message that
     * Claude gets.
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
        // A ride that stays near home, such as laps around the block, would
        // show the home area even after the cut.
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
