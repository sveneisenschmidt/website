<?php

namespace Website\Blog;

use Kirby\Filesystem\F;

/**
 * Logs as JSON Lines, one file per month: <dir>/<year>-<month>.jsonl.
 * Verlauf (Website\Changelog\Log) and the Protokoll of the assistant
 * (plugins/assistant/log.php) keep their entries this way.
 */
final class Jsonl
{
    /**
     * Appends one entry with the time and the person logged in. A change that
     * Kirby makes as its own user ("kirby") has no person. Parallel requests
     * append under a lock (F::write uses LOCK_EX).
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
        $files = glob($dir . '/*.jsonl') ?: [];
        rsort($files);
        $entries = [];
        foreach ($files as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach (array_reverse($lines) as $line) {
                $entry = json_decode($line, true);
                if (is_array($entry)) {
                    $entries[] = $entry;
                    if (count($entries) >= $limit) {
                        return $entries;
                    }
                }
            }
        }
        return $entries;
    }
}
