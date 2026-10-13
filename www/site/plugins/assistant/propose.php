<?php

use Kirby\Cms\File;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use Kirby\Data\Yaml;
use Kirby\Filesystem\F;
use Website\Blog\Types;

/**
 * The propose tools: each turns a tool call into a card, or into the error
 * Claude gets back. They change nothing; only Sven's click applies a card
 * (apply.php).
 */

/** Where "Übernehmen" writes, as the card says before the click. */
function blog_assistant_where(ModelWithContent $model): string
{
    return blog_assistant_is_draft($model)
        ? 'Übernehmen schreibt direkt in den Entwurf.'
        : 'Übernehmen schreibt in die Änderungen. Live geht es erst mit deinem Speichern im Panel.';
}

/** "Foto „Aika“", "Über mich", "IMG_1.jpg in Foto „Aika“": a model as the cards name it. */
function blog_assistant_name(ModelWithContent $model): string
{
    if ($model instanceof File) {
        return $model->filename() . ' in ' . blog_assistant_name($model->page());
    }
    $template = $model instanceof Page ? $model->intendedTemplate()->name() : '';
    return isset(Types::ALL[$template])
        ? Types::ALL[$template]['label'] . ' „' . $model->title()->value() . '“'
        : $model->title()->value();
}

/**
 * Turns a propose tool call into a card. Returns the proposal, or the
 * error Claude gets back instead.
 */
function blog_assistant_propose(array $chat, string $name, array $input): array|string
{
    // Every value of the input, each on its own: texts, captions, labels, slug, file names.
    $runs = prompt_guard_runs(blog_assistant_frozen_prompt($chat));
    $error = null;
    array_walk_recursive($input, function ($value) use ($runs, &$error) {
        $error ??= is_string($value) ? blog_guard_prompt($value, $runs) : null;
    });
    if ($error !== null) {
        return $error;
    }
    return match ($name) {
        'propose_post' => blog_assistant_propose_post($chat, $input),
        'propose_edit' => blog_assistant_propose_edit($chat, $input),
        'propose_field' => blog_assistant_propose_field($chat, $input),
        'propose_file_fields' => blog_assistant_propose_file_fields($chat, $input),
        'propose_route' => blog_assistant_propose_route($chat, $input),
        default => 'Unknown tool: ' . $name,
    };
}

/** A new card with its id, open. */
function blog_assistant_card(string $kind, string $title, array $data): array
{
    return ['id' => bin2hex(random_bytes(6)), 'kind' => $kind, 'state' => 'open', 'title' => $title, 'uploads' => [], ...$data];
}

/** The links a text may hold besides those of its field: from Sven's messages and the web search of the chat. */
function blog_assistant_links(array $chat): array
{
    return [...array_keys($chat['mine'] ?? []), ...array_keys($chat['sources'] ?? [])];
}

/** The field name as the blueprint has it, whatever its case, or null. */
function blog_assistant_field_name(array $fields, string $name): ?string
{
    $name = strtolower(trim($name));
    return isset($fields[$name]) ? $name : null;
}

/**
 * Checks a text for a field of a page by its type. Null when it passes.
 * $before: the field now. $new: what Claude wrote, when $text is the field
 * with a passage replaced.
 */
function blog_assistant_check_text(Page $page, string $type, string $text, array $allowed, string $before = '', ?string $new = null): ?string
{
    return blog_assistant_is_markdown($type)
        ? blog_guard_markdown($text, $allowed, $page, $before, $new)
        : blog_guard_plain($new ?? $text);
}

/** True for the field types the site renders as KirbyText. */
function blog_assistant_is_markdown(string $type): bool
{
    return in_array($type, ['markdown', 'textarea'], true);
}

/** The links a card marks in a text of a field of $page. */
function blog_assistant_marks(Page $page, string $type, string $text): array
{
    return blog_assistant_is_markdown($type) ? blog_guard_marks($text, $page) : [];
}

/**
 * The error for a date, or null: format YYYY-MM-DD HH:MM:SS (or YYYY-MM-DD
 * without time), Europe/Berlin, not in the future. Posts are not scheduled.
 */
function blog_assistant_date_error(string $value, bool $time = true): ?string
{
    $format = $time ? 'Y-m-d H:i:s' : 'Y-m-d';
    $berlin = new DateTimeZone('Europe/Berlin');
    $date = DateTimeImmutable::createFromFormat('!' . $format, $value, $berlin);
    if ($date === false || $date->format($format) !== $value) {
        return 'The date must be ' . ($time ? 'YYYY-MM-DD HH:MM:SS' : 'YYYY-MM-DD') . ', Europe/Berlin.';
    }
    return $date > new DateTimeImmutable('now', $berlin) ? 'The date lies in the future. Posts are not scheduled.' : null;
}

/** A value as a card shows it: toggles as ja or nein, files by their names. */
function blog_assistant_shown(Page $page, string $type, string $value): string
{
    return match ($type) {
        'toggle' => $value === 'true' ? 'ja' : 'nein',
        'files' => implode(', ', array_map(fn($ref) => blog_assistant_file_ref($page, (string) $ref)?->filename() ?? (string) $ref, blog_assistant_list($value))),
        default => $value,
    };
}

/** propose_field: a whole field of a post or of About. */
function blog_assistant_propose_field(array $chat, array $input): array|string
{
    $page = blog_assistant_find((string) $input['post']);
    if ($page === null) {
        return BLOG_ASSISTANT_NO_POST;
    }
    $fields = array_diff_key(blog_assistant_fields($page), array_flip(BLOG_ASSISTANT_ROUTE_FIELDS));
    $name = blog_assistant_field_name($fields, (string) $input['field']);
    if ($name === null) {
        return 'This field cannot be written: ' . $input['field'] . '. Fields: ' . implode(', ', array_keys($fields)) . '. Route, map points and zoom go through propose_route.';
    }
    $type = $fields[$name]['type'];
    $value = (string) $input['value'];
    $before = blog_assistant_current($page, $name);
    $stored = $value;
    switch ($type) {
        case 'date':
            $error = blog_assistant_date_error($value, (bool) ($page->blueprint()->field($name)['time'] ?? false));
            break;
        case 'toggle':
            $error = in_array($value, ['true', 'false'], true) ? null : 'A toggle takes true or false.';
            break;
        case 'files':
            $file = $page->file($value);
            $error = $file !== null && $file->template() === 'image' ? null : "No image $value in this post. Images: " . implode(', ', blog_assistant_filenames($page)) . '.';
            $stored = $file !== null ? Yaml::encode([$file->uuid()->toString()]) : '';
            break;
        case 'text':
        case 'tags':
        case 'textarea':
        case 'markdown':
            $error = blog_assistant_check_text($page, $type, $value, blog_assistant_links($chat), $before);
            break;
        default:
            $error = "The field $name ($type) cannot be written by the assistant.";
    }
    if ($error !== null) {
        return $error;
    }
    return blog_assistant_card('field', 'Feld · ' . blog_assistant_name($page), [
        'where' => blog_assistant_where($page),
        'unsaved' => blog_assistant_unsaved($page, $name),
        // marksBefore, marksAfter: the links the card marks in each.
        'diff' => [[
            'label' => $fields[$name]['label'],
            'before' => blog_assistant_shown($page, $type, $before),
            'after' => blog_assistant_shown($page, $type, $stored),
            'marksBefore' => blog_assistant_marks($page, $type, $before),
            'marksAfter' => blog_assistant_marks($page, $type, $stored),
        ]],
        'input' => ['page' => $page->id(), 'field' => $name, 'value' => $stored, 'before' => $before],
    ]);
}

/** propose_edit: one passage of a text field, in one or more versions. */
function blog_assistant_propose_edit(array $chat, array $input): array|string
{
    $page = blog_assistant_find((string) $input['post']);
    if ($page === null) {
        return BLOG_ASSISTANT_NO_POST;
    }
    $fields = array_filter(blog_assistant_fields($page), fn($f) => in_array($f['type'], ['text', 'textarea', 'markdown'], true));
    $name = blog_assistant_field_name($fields, (string) $input['field']);
    if ($name === null) {
        return 'Passages exist in text fields only: ' . implode(', ', array_keys($fields)) . '.';
    }
    $passage = (string) $input['passage'];
    $current = blog_assistant_current($page, $name);
    $count = $passage === '' ? 0 : substr_count($current, $passage);
    if ($count !== 1) {
        return $count === 0
            ? 'The passage does not occur in the field as the Panel shows it. read_post shows the field.'
            : "The passage occurs $count times in the field. It must occur exactly once.";
    }
    $versions = array_values(array_map('strval', $input['versions']));
    if ($versions === []) {
        return 'Give at least one version.';
    }
    $type = $fields[$name]['type'];
    $marks = [];
    foreach ($versions as $i => $version) {
        // A version is checked in its field: Markdown links a passage with the text around it.
        $after = str_replace($passage, $version, $current);
        $error = blog_assistant_check_text($page, $type, $after, blog_assistant_links($chat), $current, $version);
        if ($error !== null) {
            return 'Version ' . ($i + 1) . ": $error";
        }
        $marks[] = array_values(array_filter(blog_assistant_marks($page, $type, $after), fn($mark) => str_contains($version, $mark)));
    }
    return blog_assistant_card('edit', 'Passage · ' . blog_assistant_name($page), [
        'where' => blog_assistant_where($page),
        'unsaved' => blog_assistant_unsaved($page, $name),
        'field' => $fields[$name]['label'],
        'passage' => $passage,
        'versions' => $versions,
        // The links the card marks in the passage and in each version.
        'passageMarks' => array_values(array_filter(blog_assistant_marks($page, $type, $current), fn($mark) => str_contains($passage, $mark))),
        'versionMarks' => $marks,
        'input' => ['page' => $page->id(), 'field' => $name, 'passage' => $passage, 'versions' => $versions],
    ]);
}

/** propose_file_fields: alt text, caption or photographer of an image. */
function blog_assistant_propose_file_fields(array $chat, array $input): array|string
{
    $file = blog_assistant_file((string) $input['file']);
    if ($file === null || $file->template() !== 'image') {
        return 'No image found. read_post lists the file ids.';
    }
    $changes = [];
    $diff = [];
    foreach ($input['fields'] as $field) {
        $name = (string) $field['name'];
        $value = (string) $field['value'];
        if (!in_array($name, BLOG_ASSISTANT_IMAGE_TEXTS, true) || isset($changes[$name])) {
            return "Each of alt, caption and photographer at most once; $name is not possible here.";
        }
        $before = blog_assistant_current($file, $name);
        $error = $name === 'caption' ? blog_guard_caption($value, blog_assistant_links($chat), $before) : blog_guard_plain($value);
        if ($error !== null) {
            return "$name: $error";
        }
        $changes[$name] = ['before' => $before, 'after' => $value];
        $marks = fn(string $text) => $name === 'caption' ? blog_guard_marks($text, null, true) : [];
        $diff[] = ['label' => (string) ($file->blueprint()->field($name)['label'] ?? $name), 'before' => $before, 'after' => $value, 'marksBefore' => $marks($before), 'marksAfter' => $marks($value)];
    }
    if ($changes === []) {
        return 'Give at least one field.';
    }
    return blog_assistant_card('file', 'Bildtexte · ' . blog_assistant_name($file), [
        'where' => blog_assistant_where($file),
        'diff' => $diff,
        'input' => ['file' => $file->id(), 'changes' => $changes],
    ]);
}

/** A point of a route input as [lon, lat], or null when it lies outside the map. */
function blog_assistant_point(array $point): ?array
{
    $lat = (float) $point['lat'];
    $lon = (float) $point['lon'];
    return abs($lat) <= 90 && abs($lon) <= 180 ? [$lon, $lat] : null;
}

/**
 * The route of a card: stations through OSRM, or a GPX upload of this
 * chat, plus markers and zoom. Returns the route, or the error for Claude.
 */
function blog_assistant_route(array $route): array|string
{
    $stations = $route['stations'];
    $gpx = trim((string) $route['gpx']);
    if (($stations === []) === ($gpx === '')) {
        return 'A route takes either stations or a GPX upload id.';
    }
    $markers = [];
    foreach ([...array_filter($stations, fn($s) => $s['marker']), ...$route['markers']] as $marker) {
        $point = blog_assistant_point($marker);
        if ($point === null) {
            return 'A marker lies outside the map: lat -90 to 90, lon -180 to 180.';
        }
        $error = blog_guard_plain((string) $marker['label'], 60);
        if ($error !== null) {
            return 'Marker "' . $marker['label'] . '": ' . $error;
        }
        $markers[] = ['label' => (string) $marker['label'], 'lon' => $point[0], 'lat' => $point[1]];
    }
    if ($gpx !== '') {
        $meta = blog_uploads_meta($gpx);
        if ($meta === null || $meta['kind'] !== 'gpx') {
            return "No GPX upload $gpx in this chat.";
        }
        if (isset($meta['error'])) {
            return $meta['error'];
        }
        // Rides start and end at home: no marker near the uncut ends. They
        // are stored rounded; the zone grows by what rounding moves them.
        foreach ($markers as $marker) {
            foreach ($meta['ends'] as $end) {
                if (blog_route_distance([$marker['lon'], $marker['lat']], $end) < BLOG_GPX_ZONE + BLOG_GPX_ZONE_SLACK) {
                    return 'The marker "' . $marker['label'] . '" lies closer than ' . BLOG_GPX_ZONE . ' m to an end of the ride. Markers there are not possible.';
                }
            }
        }
        $line = ['line' => $meta['line'], 'km' => $meta['km'], 'seconds' => $meta['seconds'], 'source' => 'GPX', 'upload' => $gpx];
    } else {
        $points = array_map('blog_assistant_point', $stations);
        if (count($points) < 2 || in_array(null, $points, true)) {
            return 'A route takes at least two stations on the map: lat -90 to 90, lon -180 to 180.';
        }
        try {
            $line = blog_route_osrm($points, option('blog.assistant.http')) + ['source' => 'OSRM', 'upload' => null];
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
    }
    return $line + ['points' => count($line['line']), 'markers' => $markers, 'zoom' => (bool) $route['zoom']];
}

/** A route as the card shows it: km, driving time, points, markers, zoom. */
function blog_assistant_route_rows(?array $route): array
{
    if ($route === null) {
        return ['Länge' => '', 'Fahrzeit' => '', 'Punkte' => '', 'Marker' => '', 'Karte zoombar' => ''];
    }
    return [
        'Länge' => number_format($route['km'], 1, ',', '.') . ' km' . (isset($route['source']) ? ' · ' . $route['source'] : ''),
        'Fahrzeit' => $route['seconds'] === null ? '' : blog_route_duration($route['seconds']),
        'Punkte' => (string) $route['points'],
        'Marker' => implode(', ', array_column($route['markers'], 'label')),
        'Karte zoombar' => $route['zoom'] ? 'ja' : 'nein',
    ];
}

/** propose_route: a new route for a photo or trip, replacing route, points and zoom. */
function blog_assistant_propose_route(array $chat, array $input): array|string
{
    $page = blog_assistant_find((string) $input['post']);
    if ($page === null || !Types::hasMap($page->intendedTemplate()->name())) {
        return 'A route is possible for photo and trip posts only. find_posts lists the ids.';
    }
    $route = blog_assistant_route($input['route']);
    if (is_string($route)) {
        return $route;
    }
    if ($route['upload'] !== null && in_array($route['upload'], blog_assistant_needed($chat), true)) {
        return BLOG_ASSISTANT_GPX_TAKEN;
    }
    $before = blog_assistant_route_summary($page);
    $old = blog_assistant_route_rows($before['points'] > 0 || $before['markers'] !== [] ? $before : null);
    $new = blog_assistant_route_rows($route);
    return blog_assistant_card('route', 'Route · ' . blog_assistant_name($page), [
        'where' => blog_assistant_where($page),
        'uploads' => array_filter([$route['upload']]),
        'diff' => array_map(fn($label) => ['label' => $label, 'before' => $old[$label], 'after' => $new[$label]], array_keys($new)),
        'input' => ['page' => $page->id(), 'route' => $route, 'hash' => $before['hash']],
    ]);
}

/**
 * The new draft as a page in memory only, with its photos as files: the
 * texts render in it as they will in the draft.
 */
function blog_assistant_draft(Page $section, string $type, string $slug, array $photos): Page
{
    return Page::factory([
        'slug' => $slug,
        'template' => $type,
        'parent' => $section,
        'isDraft' => true,
        'files' => array_map(fn($p) => ['filename' => $p['filename'], 'template' => 'image', 'content' => ['alt' => $p['alt'], 'caption' => $p['caption']]], $photos),
    ]);
}

/** propose_post: a new draft with fields, photos and route in one click. */
function blog_assistant_propose_post(array $chat, array $input): array|string
{
    $type = (string) $input['type'];
    if (!isset(Types::ALL[$type]) || site()->find(Types::ALL[$type]['section']) === null) {
        return 'No section for the type ' . $type . '.';
    }
    $slug = (string) $input['slug'];
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
        return 'A slug takes a-z, 0-9 and single hyphens.';
    }
    if (($taken = blog_assistant_slug_taken($slug)) !== null) {
        return "The slug $slug is taken by $taken. The URL must be unique.";
    }
    $date = (string) $input['date'];
    if (($error = blog_assistant_date_error($date)) !== null) {
        return $error;
    }
    $title = trim((string) $input['title']);
    if ($title === '' || ($error = blog_guard_plain($title)) !== null) {
        return 'title: ' . ($error ?? 'A post needs a title.');
    }
    $trip = trim((string) $input['trip']);
    if ($trip !== '' && ($type !== 'trip' || ($error = blog_guard_plain($trip)) !== null)) {
        return 'trip: ' . ($error ?? 'only trip posts have this field.');
    }
    $needed = blog_assistant_needed($chat);
    $allowed = blog_assistant_links($chat);
    $photos = [];
    foreach ($input['photos'] as $photo) {
        $id = (string) $photo['upload'];
        $filename = (string) $photo['filename'];
        $meta = blog_uploads_meta($id);
        if ($meta === null || $meta['kind'] !== 'image' || $meta['path'] === null) {
            return "No image upload $id in this chat.";
        }
        if (in_array($id, $needed, true) || in_array($id, array_column($photos, 'upload'), true)) {
            return "The upload $id is used by another open card or twice here.";
        }
        if (F::safeName($filename) !== $filename || F::extension($filename) !== $meta['ext']) {
            return "The file name $filename takes a-z, 0-9, - and _ and the extension .{$meta['ext']}, as in {$meta['name']}.";
        }
        if (in_array($filename, array_column($photos, 'filename'), true)) {
            return "The file name $filename comes twice.";
        }
        foreach (['alt', 'photographer'] as $key) {
            if (($error = blog_guard_plain((string) $photo[$key])) !== null) {
                return "$filename, $key: $error";
            }
        }
        if (($error = blog_guard_caption((string) $photo['caption'], $allowed)) !== null) {
            return "$filename, caption: $error";
        }
        $photos[] = ['upload' => $id, 'filename' => $filename, 'alt' => (string) $photo['alt'], 'caption' => (string) $photo['caption'], 'photographer' => (string) $photo['photographer'], 'exif' => $meta['exif'], 'marks' => blog_guard_marks((string) $photo['caption'], null, true)];
    }
    $names = array_column($photos, 'filename');
    $cover = (string) $input['cover'];
    if ($cover !== '' && !in_array($cover, $names, true)) {
        return 'cover is one of the file names: ' . implode(', ', $names) . '.';
    }
    $map = Types::hasMap($type);
    // The texts render in the draft as it will be: its images, its map.
    $draft = blog_assistant_draft(site()->find(Types::ALL[$type]['section']), $type, $slug, $photos);
    foreach (['intro', 'text'] as $key) {
        if (($error = blog_guard_markdown((string) $input[$key], $allowed, $draft)) !== null) {
            return "$key: $error";
        }
    }
    $route = null;
    if ($input['route']['stations'] !== [] || trim((string) $input['route']['gpx']) !== '') {
        if (!$map) {
            return 'A route is possible for photo and trip posts only.';
        }
        $route = blog_assistant_route($input['route']);
        if (is_string($route)) {
            return "route: $route";
        }
        if ($route['upload'] !== null && in_array($route['upload'], $needed, true)) {
            return BLOG_ASSISTANT_GPX_TAKEN;
        }
    }
    $content = ['title' => $title, 'date' => $date, 'intro' => (string) $input['intro'], 'text' => (string) $input['text'], 'pick' => $input['pick'] ? 'true' : 'false'] + ($type === 'trip' ? ['trip' => $trip] : []);
    $rows = [
        ['label' => 'Titel', 'value' => $title],
        ['label' => 'URL-Anhang', 'value' => $slug],
        ['label' => 'Datum', 'value' => $date],
        ['label' => 'Anriss', 'value' => $content['intro'], 'marks' => blog_guard_marks($content['intro'], $draft)],
        ['label' => 'Text', 'value' => $content['text'], 'marks' => blog_guard_marks($content['text'], $draft)],
        ['label' => 'Titelbild', 'value' => $cover],
        ['label' => 'Hervorgehoben', 'value' => $input['pick'] ? 'ja' : 'nein'],
        ...($type === 'trip' ? [['label' => 'Reise', 'value' => $trip]] : []),
    ];
    foreach ($route === null ? [] : blog_assistant_route_rows($route) as $label => $value) {
        $rows[] = ['label' => $label, 'value' => $value];
    }
    return blog_assistant_card('post', 'Neuer Entwurf · ' . Types::ALL[$type]['label'], [
        'where' => 'Anlegen legt einen Entwurf an. Veröffentlichen bleibt bei dir.',
        'uploads' => [...array_column($photos, 'upload'), ...array_filter([$route['upload'] ?? null])],
        'rows' => $rows,
        'photos' => array_map(fn($p) => array_diff_key($p, ['exif' => true]), $photos),
        'input' => ['type' => $type, 'slug' => $slug, 'content' => $content, 'photos' => array_map(fn($p) => array_diff_key($p, ['marks' => true]), $photos), 'cover' => $cover, 'route' => $route],
    ]);
}
