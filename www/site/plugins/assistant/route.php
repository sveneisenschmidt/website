<?php

use Kirby\Http\Remote;

/**
 * Routes for the map of a post (spec, "Tools" and "GPX und Svens Zuhause"):
 * a car route from the public OSRM server, or the line of a GPX file.
 * Points are [lon, lat], as in GeoJSON. Coordinates keep 5 decimals.
 */

const BLOG_ROUTE_OSRM = 'https://router.project-osrm.org/route/v1/driving/';

/** Own User-Agent for OSRM: one request per proposal, no retry. */
const BLOG_ROUTE_AGENT = 'sven.eisenschmidt.website/assistant (Kirby; one request per proposal)';

/** Douglas-Peucker tolerance in degrees: OSRM lines and GPX tracks. */
const BLOG_ROUTE_EPSILON = 0.0005;
const BLOG_GPX_EPSILON = 0.0002;

/** GPX files: at most 5 MB and 100,000 points. */
const BLOG_GPX_MAX_BYTES = 5 * 1024 * 1024;
const BLOG_GPX_MAX_POINTS = 100000;

/** Each end of a ride loses a random length between these, in meters. */
const BLOG_GPX_TRIM_MIN = 700;
const BLOG_GPX_TRIM_MAX = 1200;

/** No marker closer than this to an end of the ride before trimming, in meters. */
const BLOG_GPX_ZONE = 1200;

/**
 * The uncut ends stay on the server for the marker check, to 3 decimals
 * only. Rounding moves a point by at most 0.0005° in each direction, 56 m
 * at most, 79 m together: the check adds that.
 */
const BLOG_GPX_ENDS_DECIMALS = 3;
const BLOG_GPX_ZONE_SLACK = 80;

/** A ride that never gets farther than this from its ends gives no route, in meters. */
const BLOG_GPX_NEAR = 1500;

/** A ride shorter than this after trimming gives no route, in meters. */
const BLOG_GPX_MIN_LENGTH = 3000;

/** Distance in meters between two points [lon, lat] (haversine). */
function blog_route_distance(array $a, array $b): float
{
    [$lon1, $lat1] = $a;
    [$lon2, $lat2] = $b;
    $x = sin(deg2rad($lat2 - $lat1) / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lon2 - $lon1) / 2) ** 2;
    return 2 * 6371000 * asin(min(1, sqrt($x)));
}

/** Length of a line in meters. */
function blog_route_length(array $points): float
{
    $length = 0.0;
    for ($i = 1; $i < count($points); $i++) {
        $length += blog_route_distance($points[$i - 1], $points[$i]);
    }
    return $length;
}

/** Douglas-Peucker without recursion: a GPX file has up to 100,000 points. */
function blog_route_simplify(array $points, float $epsilon): array
{
    $count = count($points);
    if ($count < 3) {
        return array_values($points);
    }
    $keep = array_fill(0, $count, false);
    $keep[0] = $keep[$count - 1] = true;
    $stack = [[0, $count - 1]];
    while ($stack !== []) {
        [$start, $end] = array_pop($stack);
        [$ax, $ay] = $points[$start];
        [$bx, $by] = $points[$end];
        $dx = $bx - $ax;
        $dy = $by - $ay;
        $norm = sqrt($dx * $dx + $dy * $dy);
        $max = 0.0;
        $index = 0;
        for ($i = $start + 1; $i < $end; $i++) {
            [$px, $py] = $points[$i];
            $d = $norm == 0.0
                ? sqrt(($px - $ax) ** 2 + ($py - $ay) ** 2)
                : abs($dx * ($ay - $py) - ($ax - $px) * $dy) / $norm;
            if ($d > $max) {
                $max = $d;
                $index = $i;
            }
        }
        if ($max > $epsilon) {
            $keep[$index] = true;
            $stack[] = [$start, $index];
            $stack[] = [$index, $end];
        }
    }
    return array_values(array_filter($points, fn ($i) => $keep[$i], ARRAY_FILTER_USE_KEY));
}

/** 5 decimals, without points that repeat the one before. */
function blog_route_round(array $points): array
{
    $out = [];
    foreach ($points as [$lon, $lat]) {
        $point = [round((float)$lon, 5), round((float)$lat, 5)];
        if ($out === [] || end($out) !== $point) {
            $out[] = $point;
        }
    }
    return $out;
}

/** A coordinate as text: at most 6 decimals, no trailing zeros. */
function blog_route_num(float $value): string
{
    return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
}

/** "1 h 52 min", "35 min". */
function blog_route_duration(int $seconds): string
{
    $minutes = (int)round($seconds / 60);
    return $minutes >= 60 ? intdiv($minutes, 60) . ' h ' . ($minutes % 60) . ' min' : $minutes . ' min';
}

/** The points of a route.json (a GeoJSON LineString), or none. */
function blog_route_line(string $json): array
{
    $data = json_decode($json, true);
    $points = is_array($data) && ($data['type'] ?? '') === 'LineString' ? ($data['coordinates'] ?? []) : [];
    return array_values(array_filter($points, fn ($p) => is_array($p) && count($p) >= 2 && is_numeric($p[0]) && is_numeric($p[1])));
}

/**
 * The indexes of the first and last point that stay when both ends are
 * cut: the start until $head meters along the line lie behind it and the
 * point is $head meters or more from both ends in a straight line, the end
 * the same with $tail. Rides start and end at home, and a standstill there
 * walks along the line without leaving (security review, 2026-10-12). The
 * distance counts for the point as Claude gets it, to 5 decimals.
 */
function blog_route_cut(array $points, float $head, float $tail): array
{
    $ends = [$points[0], $points[count($points) - 1]];
    $away = function (array $point, float $length) use ($ends): bool {
        $point = [round((float)$point[0], 5), round((float)$point[1], 5)];
        return blog_route_distance($ends[0], $point) >= $length && blog_route_distance($ends[1], $point) >= $length;
    };
    $first = 0;
    $walked = 0.0;
    while ($first < count($points) - 1 && ($walked < $head || !$away($points[$first], $head))) {
        $walked += blog_route_distance($points[$first], $points[$first + 1]);
        $first++;
    }
    $last = count($points) - 1;
    $walked = 0.0;
    while ($last > $first && ($walked < $tail || !$away($points[$last], $tail))) {
        $walked += blog_route_distance($points[$last], $points[$last - 1]);
        $last--;
    }
    return [$first, $last];
}

/**
 * One request to OSRM for a car route through the stations ([lon, lat]).
 * No retry. $http replaces the request in tests: fn (string $url, string $agent): [status, body].
 * Throws RuntimeException with the message Claude gets.
 */
function blog_route_osrm(array $stations, ?Closure $http = null): array
{
    $url = BLOG_ROUTE_OSRM . implode(';', array_map(fn (array $p) => blog_route_num((float)$p[0]) . ',' . blog_route_num((float)$p[1]), $stations)) . '?overview=full&geometries=geojson';
    try {
        if ($http !== null) {
            [$status, $body] = $http($url, BLOG_ROUTE_AGENT);
        } else {
            $remote = Remote::get($url, ['agent' => BLOG_ROUTE_AGENT, 'timeout' => 20]);
            [$status, $body] = [$remote->code(), (string)$remote->content()];
        }
    } catch (Throwable) {
        $status = 0;
    }
    if (!$status) {
        throw new RuntimeException('OSRM is not reachable. No route.');
    }
    $data = json_decode((string)$body, true);
    $route = $data['routes'][0] ?? null;
    $line = blog_route_round(blog_route_simplify($route['geometry']['coordinates'] ?? [], BLOG_ROUTE_EPSILON));
    if ($status !== 200 || ($data['code'] ?? '') !== 'Ok' || count($line) < 2) {
        throw new RuntimeException('OSRM found no route' . (isset($data['message']) ? ': ' . $data['message'] : '') . '.');
    }
    return ['line' => $line, 'km' => round((float)$route['distance'] / 1000, 1), 'seconds' => (int)round((float)$route['duration'])];
}

/**
 * Track points and times of a GPX file, read without DOCTYPE, network or
 * entities. Throws RuntimeException with the message the Panel shows.
 * Names, devices and extensions are never read.
 */
function blog_gpx_read(string $path): array
{
    if (!is_file($path) || filesize($path) > BLOG_GPX_MAX_BYTES) {
        throw new RuntimeException('Die GPX-Datei ist größer als 5 MB.');
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
                if (!is_numeric($lat) || !is_numeric($lon) || abs((float)$lat) > 90 || abs((float)$lon) > 180) {
                    throw new RuntimeException('Ein Punkt der GPX-Datei hat keine gültigen Koordinaten.');
                }
                if (count($points) === BLOG_GPX_MAX_POINTS) {
                    throw new RuntimeException('Die GPX-Datei hat mehr als 100.000 Punkte.');
                }
                $points[] = [(float)$lon, (float)$lat];
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
 */
function blog_gpx_line(array $points, array $times): array
{
    if (count($points) < 2) {
        return ['error' => 'The GPX file has no track points.'];
    }
    $ends = [$points[0], $points[count($points) - 1]];
    // Laps around the block show the block, cut or not.
    $far = false;
    foreach ($points as $point) {
        if (blog_route_distance($ends[0], $point) > BLOG_GPX_NEAR && blog_route_distance($ends[1], $point) > BLOG_GPX_NEAR) {
            $far = true;
            break;
        }
    }
    if (!$far) {
        return ['error' => 'The GPX track never gets farther than 1.5 km from its start and end. It gives no route.'];
    }
    [$first, $last] = blog_route_cut($points, random_int(BLOG_GPX_TRIM_MIN, BLOG_GPX_TRIM_MAX), random_int(BLOG_GPX_TRIM_MIN, BLOG_GPX_TRIM_MAX));
    $kept = array_slice($points, $first, $last - $first + 1);
    $length = blog_route_length($kept);
    if (count($kept) < 2 || $length < BLOG_GPX_MIN_LENGTH) {
        return ['error' => 'The GPX track is shorter than 3 km after cutting its ends.'];
    }
    return [
        'line' => blog_route_round(blog_route_simplify($kept, BLOG_GPX_EPSILON)),
        'km' => round($length / 1000, 1),
        'seconds' => isset($times[$first], $times[$last]) ? max(0, $times[$last] - $times[$first]) : null,
        'ends' => array_map(fn ($end) => [round((float)$end[0], BLOG_GPX_ENDS_DECIMALS), round((float)$end[1], BLOG_GPX_ENDS_DECIMALS)], $ends),
    ];
}
