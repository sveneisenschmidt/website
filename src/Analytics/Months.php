<?php

namespace Website\Analytics;

use Kirby\Data\Json;
use Throwable;
use Website\Blog\JsonFile;

/**
 * The daily totals of "Besucher", one file per month:
 * content/.analytics/<year>-<month>.json. State, like the change log.
 *
 * @phpstan-type Day array{visits?: int, views?: int, pages?: array<string, int>, referrers?: array<string, int>}
 */
final class Months
{
    public static function dir(): string
    {
        return kirby()->root('content') . '/.analytics';
    }

    /**
     * The days of one month ("2026-10"), keyed by date. An unreadable file
     * throws when $strict, else it reads as empty, so the Panel still opens.
     *
     * @return array<string, Day>
     */
    public static function read(string $month, bool $strict = false): array
    {
        $file = self::dir() . "/$month.json";
        try {
            return is_file($file) ? Json::read($file) : [];
        } catch (Throwable $e) {
            if ($strict) {
                throw $e;
            }
            error_log('blog analytics: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Saves the days of one month, through a temporary file.
     *
     * @param array<string, Day> $days
     */
    public static function write(string $month, array $days): void
    {
        JsonFile::write(self::dir() . "/$month.json", $days);
    }

    /**
     * The months with a file, oldest first.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_map(fn(string $file) => basename($file, '.json'), glob(self::dir() . '/*.json') ?: []);
    }
}
