<?php

namespace Website\Blog;

use Generator;
use Kirby\Filesystem\F;

/**
 * Writes and reads a log in the JSON Lines format: one JSON object per
 * line, one file per month (<dir>/<year>-<month>.jsonl). The change log
 * (Website\Changelog\Log) and the log of the assistant
 * (Website\Assistant\Log) use it.
 */
final class Jsonl
{
    /**
     * Appends one entry and adds the time and the user logged in to the
     * Panel. Kirby makes some changes as its internal user "kirby", for
     * example inside impersonate(); such an entry has no user. F::write()
     * appends with LOCK_EX, so parallel requests do not mix their lines.
     *
     * @param array<string, mixed> $entry
     */
    public static function append(string $dir, array $entry): void
    {
        $user = kirby()->user();
        $person = $user === null || $user->isKirby() ? null : $user;
        $entry = [
            'time' => date('c'),
            'user' => $person?->email(),
            'name' => $person?->name()->or($person->email())->value(),
            ...$entry,
        ];
        F::write($dir . '/' . date('Y-m') . '.jsonl', json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", true);
    }

    /**
     * The newest entries first, at most $limit.
     *
     * @return list<array<string, mixed>>
     */
    public static function read(string $dir, int $limit): array
    {
        $entries = [];
        foreach (self::newest($dir) as $entry) {
            if (count($entries) >= $limit) {
                break;
            }
            $entries[] = $entry;
        }
        return $entries;
    }

    /**
     * Every entry, the newest first, or only those of the newest $months
     * files. It reads one file at a time, the newest month first, so a
     * caller that stops early reads only the newest files.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public static function newest(string $dir, ?int $months = null): Generator
    {
        $files = glob($dir . '/*.jsonl') ?: [];
        rsort($files);
        foreach (array_slice($files, 0, $months) as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach (array_reverse($lines) as $line) {
                $entry = json_decode($line, true);
                if (is_array($entry)) {
                    yield $entry;
                }
            }
        }
    }
}
