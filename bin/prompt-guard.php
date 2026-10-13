<?php

/**
 * Git hook that keeps the prompt of the Panel assistant out of this public
 * repository. The prompt is stored only in the field `assistantPrompt` of
 * www/content/site.txt (in the Panel: Website › Assistent), and content is
 * not in git. The hook stops a commit or a push when its added lines contain
 * a run of 8 consecutive words of the prompt. This file contains no part of
 * the prompt.
 *
 * make hooks copies this file and src/Assistant/Words.php into .git/hooks.
 * The hook then runs on every branch and needs neither Kirby nor Composer.
 *
 * Usage: php prompt-guard.php pre-commit
 *        php prompt-guard.php pre-push < "<local ref> <local sha> <remote ref> <remote sha>" lines
 */

use Website\Assistant\Words;

// `Words` splits a text into runs of 8 words; the assistant uses it to
// check its proposals too. In .git/hooks, make hooks puts it next to this
// file as prompt-words.php.
if (!class_exists(Words::class, false)) {
    require_once is_file(__DIR__ . '/prompt-words.php') ? __DIR__ . '/prompt-words.php' : __DIR__ . '/../src/Assistant/Words.php';
}

/**
 * Reads the field `assistantPrompt` from a site.txt in Kirby's text format.
 * Returns "" when the file or the field is missing.
 */
function prompt_guard_prompt(string $file): string
{
    if (!is_file($file)) {
        return '';
    }
    foreach (preg_split('/\R----\s*\R/', (string) file_get_contents($file)) as $field) {
        if (preg_match('/^\s*assistant[-_ ]?prompt\s*:(.*)$/is', $field, $m)) {
            // Kirby writes a line "----" inside a value as "\----".
            return trim(str_replace("\n\\----", "\n----", $m[1]));
        }
    }
    return '';
}

/**
 * Returns the files of a unified diff whose added lines contain a run of
 * 8 words of the prompt.
 *
 * @return array<mixed>
 */
function prompt_guard_hits(string $prompt, string $diff): array
{
    $runs = Words::runs($prompt);
    if ($runs === []) {
        return [];
    }
    $added = [];
    $file = '?';
    foreach (preg_split('/\R/', $diff) as $line) {
        if (str_starts_with($line, '+++ ')) {
            $file = preg_replace('#^\+\+\+ (b/)?#', '', $line);
        } elseif (str_starts_with($line, '+')) {
            $added[$file][] = substr($line, 1);
        }
    }
    $hits = [];
    foreach ($added as $name => $lines) {
        if (array_intersect_key(Words::runs(implode("\n", $lines)), $runs) !== []) {
            $hits[] = $name;
        }
    }
    return $hits;
}

/** Runs the hook: exit code 1 stops the commit or the push. */
function prompt_guard_main(string $mode): int
{
    $top = trim((string) shell_exec('git rev-parse --show-toplevel'));
    $prompt = prompt_guard_prompt($top . '/www/content/site.txt');
    if ($prompt === '') {
        return 0;
    }
    $diffs = [];
    if ($mode === 'pre-push') {
        while (($line = fgets(STDIN)) !== false) {
            $sha = explode(' ', trim($line))[1] ?? '';
            // The push of a deleted branch has the sha 000…0 and no commits.
            if (preg_match('/^[0-9a-f]+$/', $sha) && trim($sha, '0') !== '') {
                $diffs[] = (string) shell_exec('git log -p --no-color --no-ext-diff -U0 --format= ' . escapeshellarg($sha) . ' --not --remotes');
            }
        }
    } else {
        $diffs[] = (string) shell_exec('git diff --cached --no-color --no-ext-diff -U0');
    }
    $hits = [];
    foreach ($diffs as $diff) {
        $hits = [...$hits, ...prompt_guard_hits($prompt, $diff)];
    }
    if ($hits === []) {
        return 0;
    }
    fwrite(STDERR, "Diese Dateien enthalten Text aus dem Prompt (Website › Assistent):\n  " . implode("\n  ", array_unique($hits)) . "\nDer Commit oder Push ist abgebrochen.\n");
    return 1;
}

if (realpath($_SERVER['argv'][0] ?? '') === __FILE__) {
    exit(prompt_guard_main($_SERVER['argv'][1] ?? 'pre-commit'));
}
