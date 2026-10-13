<?php

namespace Website\Assistant;

use Closure;
use Kirby\Http\Remote;
use RuntimeException;
use Throwable;
use Website\Blog\Text;

/**
 * Builds the route on the map of a photo or trip post. It gets car routes
 * from the public server of OSRM, an open-source routing service, and holds
 * the geometry that `Gpx` uses as well. Points are [lon, lat], the order of
 * GeoJSON. Coordinates keep 5 decimals, about 1 m.
 */
final class Route
{
    public const OSRM = 'https://router.project-osrm.org/route/v1/driving/';

    /**
     * The User-Agent for OSRM names the site and its use. The usage policy of
     * the public server asks for a User-Agent that identifies the client and
     * for few requests. The assistant sends one per proposal, without a retry.
     */
    public static function agent(): string
    {
        return (string) parse_url(site()->url(), PHP_URL_HOST) . '/assistant (Kirby; one request per proposal)';
    }

    /** Tolerance of `simplify()` for OSRM lines in degrees, about 55 m. GPX tracks use `Gpx::EPSILON`. */
    public const EPSILON = 0.0005;

    /**
     * Distance in meters between two points [lon, lat] (haversine).
     *
     * @param array<int, float|int> $a
     * @param array<int, float|int> $b
     */
    public static function distance(array $a, array $b): float
    {
        [$lon1, $lat1] = $a;
        [$lon2, $lat2] = $b;
        $x = sin(deg2rad($lat2 - $lat1) / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lon2 - $lon1) / 2) ** 2;
        return 2 * 6371000 * asin(min(1, sqrt($x)));
    }

    /**
     * Length of a line in meters.
     *
     * @param list<array<int, float|int>> $points
     */
    public static function length(array $points): float
    {
        $length = 0.0;
        for ($i = 1; $i < count($points); $i++) {
            $length += self::distance($points[$i - 1], $points[$i]);
        }
        return $length;
    }

    /**
     * Removes the points that lie closer than $epsilon to the line through
     * their neighbours (Douglas-Peucker algorithm). A stack replaces the
     * recursion, because a GPX file has up to 100,000 points.
     *
     * @param array<int, array<int, float|int>> $points
     * @return list<array<int, float|int>>
     */
    public static function simplify(array $points, float $epsilon): array
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
        return array_values(array_filter($points, fn($i) => $keep[$i], ARRAY_FILTER_USE_KEY));
    }

    /**
     * Rounds points to 5 decimals and drops a point that equals the one before.
     *
     * @param array<array<int, mixed>> $points
     * @return list<array{float, float}>
     */
    public static function round(array $points): array
    {
        $out = [];
        foreach ($points as [$lon, $lat]) {
            $point = [round((float) $lon, 5), round((float) $lat, 5)];
            if ($out === [] || end($out) !== $point) {
                $out[] = $point;
            }
        }
        return $out;
    }

    /** "1 h 52 min", "35 min". */
    public static function duration(int $seconds): string
    {
        $minutes = (int) round($seconds / 60);
        return $minutes >= 60 ? intdiv($minutes, 60) . ' h ' . ($minutes % 60) . ' min' : $minutes . ' min';
    }

    /**
     * The points of a route.json (a GeoJSON LineString), or none.
     *
     * @return list<array<int, mixed>>
     */
    public static function line(string $json): array
    {
        $data = json_decode($json, true);
        $points = is_array($data) && ($data['type'] ?? '') === 'LineString' ? ($data['coordinates'] ?? []) : [];
        return array_values(array_filter($points, fn($p) => is_array($p) && count($p) >= 2 && is_numeric($p[0]) && is_numeric($p[1])));
    }

    /**
     * The indexes of the first and the last point that stay when both ends of
     * a ride are cut. The start is cut until the line has run $head meters
     * and the point lies at least $head meters from both ends in a straight
     * line. The end is cut the same way with $tail. Rides start and end at
     * home. GPS drift during a stop at home adds length to the line without
     * moving away, so the length alone is not enough (security review of
     * 2026-10-12). The distances use the point as Claude gets it, rounded to
     * 5 decimals.
     *
     * @param list<array<int, float|int>> $points
     * @return array{int, int}
     */
    public static function cut(array $points, float $head, float $tail): array
    {
        $ends = [$points[0], $points[count($points) - 1]];
        $away = function (array $point, float $length) use ($ends): bool {
            $point = [round((float) $point[0], 5), round((float) $point[1], 5)];
            return self::distance($ends[0], $point) >= $length && self::distance($ends[1], $point) >= $length;
        };
        $first = 0;
        $walked = 0.0;
        while ($first < count($points) - 1 && ($walked < $head || !$away($points[$first], $head))) {
            $walked += self::distance($points[$first], $points[$first + 1]);
            $first++;
        }
        $last = count($points) - 1;
        $walked = 0.0;
        while ($last > $first && ($walked < $tail || !$away($points[$last], $tail))) {
            $walked += self::distance($points[$last], $points[$last - 1]);
            $last--;
        }
        return [$first, $last];
    }

    /**
     * Sends one request to OSRM for a car route through the stations
     * ([lon, lat]), without a retry. Tests replace the request with $http:
     * fn (string $url, string $agent): [status, body]. Throws
     * RuntimeException with the message that Claude gets.
     *
     * @param list<array<int, float|int>> $stations
     * @return array{line: list<array{float, float}>, km: float, seconds: int}
     */
    public static function osrm(array $stations, ?Closure $http = null): array
    {
        $url = self::OSRM . implode(';', array_map(fn(array $p) => Text::number((float) $p[0], 6) . ',' . Text::number((float) $p[1], 6), $stations)) . '?overview=full&geometries=geojson';
        try {
            if ($http !== null) {
                [$status, $body] = $http($url, self::agent());
            } else {
                $remote = Remote::get($url, ['agent' => self::agent(), 'timeout' => 20]);
                [$status, $body] = [$remote->code(), (string) $remote->content()];
            }
        } catch (Throwable $e) {
            error_log('blog assistant: OSRM ' . $e::class . ': ' . $e->getMessage());
            $status = 0;
        }
        if (!$status) {
            throw new RuntimeException('OSRM is not reachable. No route.');
        }
        $data = json_decode((string) $body, true);
        $route = $data['routes'][0] ?? null;
        $line = self::round(self::simplify($route['geometry']['coordinates'] ?? [], self::EPSILON));
        if ($status !== 200 || ($data['code'] ?? '') !== 'Ok' || count($line) < 2) {
            throw new RuntimeException('OSRM found no route' . (isset($data['message']) ? ': ' . $data['message'] : '') . '.');
        }
        return ['line' => $line, 'km' => round((float) $route['distance'] / 1000, 1), 'seconds' => (int) round((float) $route['duration'])];
    }
}
