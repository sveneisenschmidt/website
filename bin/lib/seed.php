<?php

use Kirby\Data\Txt;

/**
 * Site fields that live only in the local site and on the server, never in
 * Hugo or git: the assistant's prompt and model (spec, "System-Prompt").
 * A new import keeps them.
 */
const SEED_KEEP_SITE_FIELDS = ['assistantPrompt', 'assistantModel'];

/**
 * Copies the kept fields from the old site.txt into the new one; the new
 * file keeps its time. Returns the names of the fields copied.
 */
function seed_keep_site_fields(string $old, string $new, array $fields = SEED_KEEP_SITE_FIELDS): array
{
    if (!is_file($old) || !is_file($new)) {
        return [];
    }
    $from = Txt::decode((string)file_get_contents($old));
    $to = Txt::decode((string)file_get_contents($new));
    $kept = [];
    foreach ($fields as $field) {
        $value = trim((string)($from[strtolower($field)] ?? ''));
        if ($value !== '') {
            $to[strtolower($field)] = $value;
            $kept[] = $field;
        }
    }
    if ($kept !== []) {
        $time = filemtime($new);
        file_put_contents($new, Txt::encode($to) . "\n");
        touch($new, $time);
    }
    return $kept;
}
