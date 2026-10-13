<?php

/**
 * Git hook against a leak of the assistant's prompt.
 * The prompt lives only in Website › Assistent, the field assistantPrompt
 * in www/content/site.txt; this repository is public. The hook stops a
 * commit or push whose added lines hold a run of 8 words of the prompt.
 * It holds no part of the prompt itself.
 *
 * make hooks copies this file into the hooks folder of git, so it
 * runs on every branch, main included. Standalone: no Kirby, no Composer.
 *
 * Usage: php prompt-guard.php pre-commit
 *        php prompt-guard.php pre-push < "<local ref> <local sha> <remote ref> <remote sha>" lines
 */

// The runs of words, shared with the assistant's check of its proposals.
// The copy in the hooks folder finds them next to itself.
require_once is_file(__DIR__ . '/prompt-words.php') ? __DIR__ . '/prompt-words.php' : __DIR__ . '/../www/site/plugins/assistant/words.php';

/** The prompt from a site.txt (Kirby's text format), or "". */
function prompt_guard_prompt(string $file): string
{
    if (!is_file($file)) {
        return '';
    }
    foreach (preg_split('/\R----\s*\R/', (string) file_get_contents($file)) as $field) {
        if (preg_match('/^\s*assistant[-_ ]?prompt\s*:(.*)$/is', $field, $m)) {
            return trim(str_replace("\n\\----", "\n----", $m[1]));
        }
    }
    return '';
}

/** The files of a unified diff whose added lines hold a run of the prompt. */
function prompt_guard_hits(string $prompt, string $diff): array
{
    $runs = prompt_guard_runs($prompt);
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
        if (array_intersect_key(prompt_guard_runs(implode("\n", $lines)), $runs) !== []) {
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
            // A deleted branch pushes nothing.
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
    fwrite(STDERR, "Abbruch: Text aus dem Prompt (Website › Assistent) in:\n  " . implode("\n  ", array_unique($hits)) . "\nNichts committet oder gepusht.\n");
    return 1;
}

if (realpath($_SERVER['argv'][0] ?? '') === __FILE__) {
    exit(prompt_guard_main($_SERVER['argv'][1] ?? 'pre-commit'));
}
