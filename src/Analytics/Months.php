<?php

namespace Website\Analytics;

use Kirby\Data\Json;
use Throwable;

/**
 * Reads the daily visitor totals from before Hits existed, one JSON file per
 * month in the folder of Hits: content/.analytics/<year>-<month>.json.
 * Totals adds them to the days in Hits.
 *
 * @phpstan-type Day array{visits?: int, views?: int, pages?: array<string, int>, referrers?: array<string, int>}
 */
final class Months
{
    /**
     * The days of one month ("2026-10"), keyed by date. An unreadable file
     * is logged and reads as empty, so the Panel view still opens.
     *
     * @return array<string, Day>
     */
    public static function read(string $month): array
    {
        $file = Hits::dir() . "/$month.json";
        try {
            return is_file($file) ? Json::read($file) : [];
        } catch (Throwable $e) {
            error_log('blog analytics: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * The months with a file, oldest first.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_map(fn(string $file) => basename($file, '.json'), glob(Hits::dir() . '/*.json') ?: []);
    }
}
