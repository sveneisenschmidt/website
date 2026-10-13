<?php

namespace Website\Assistant;

/**
 * Splits a text into runs of 8 consecutive words. The prompt is the text
 * under Website › Assistent that tells Claude how the author writes. The git
 * hook bin/prompt-guard.php stops a commit or push whose new lines contain a
 * run from the prompt, so the prompt never reaches this public repository.
 * `Guard::prompt()` rejects a proposal that contains such a run, so the
 * prompt never reaches a post. The file uses neither Kirby nor Composer:
 * `make hooks` copies it into the git hooks folder as prompt-words.php, and
 * the hook loads it from there.
 */
final class Words
{
    public const LENGTH = 8;

    /**
     * The words of a text in lowercase. Punctuation and Markdown marks
     * separate words and do not count, so a run still matches when only the
     * formatting differs.
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Every run of `LENGTH` consecutive words, as array keys, so that
     * `array_intersect_key()` can compare two texts.
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
