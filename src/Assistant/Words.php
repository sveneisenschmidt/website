<?php

namespace Website\Assistant;

/**
 * Runs of words of the prompt, shared by the git hook bin/prompt-guard.php
 * (no prompt in git) and Guard (no prompt in a proposal). Standalone: no
 * Kirby, no Composer; make hooks copies it next to the hook as
 * prompt-words.php, where the hook requires it by its path.
 */
final class Words
{
    public const LENGTH = 8;

    /**
     * Lowercase words, without punctuation and Markdown.
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Every run of LENGTH words of a text, as keys.
     *
     * @return array<string, true>
     */
    public static function runs(string $text): array
    {
        $words = self::words($text);
        $runs = [];
        for ($i = 0; $i + self::LENGTH <= count($words); $i++) {
            $runs[implode(' ', array_slice($words, $i, self::LENGTH))] = true;
        }
        return $runs;
    }
}
