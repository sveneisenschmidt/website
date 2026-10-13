<?php

/**
 * Runs of words of the prompt, shared by the git hook bin/prompt-guard.php
 * (no prompt in git) and guard.php (no prompt in a proposal). Standalone:
 * no Kirby, no Composer; make hooks copies it next to the hook as
 * prompt-words.php.
 */

const PROMPT_GUARD_WORDS = 8;

/** Lowercase words, without punctuation and Markdown. */
function prompt_guard_words(string $text): array
{
    return preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

/** Every run of PROMPT_GUARD_WORDS words of a text, as keys. */
function prompt_guard_runs(string $text): array
{
    $words = prompt_guard_words($text);
    $runs = [];
    for ($i = 0; $i + PROMPT_GUARD_WORDS <= count($words); $i++) {
        $runs[implode(' ', array_slice($words, $i, PROMPT_GUARD_WORDS))] = true;
    }
    return $runs;
}
