<?php

use Kirby\Cms\File;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use Kirby\Data\Yaml;
use Kirby\Filesystem\F;

/**
 * The tools Claude may call. Read tools run at once. Propose tools change
 * nothing: they return a card, and only Sven's click applies it
 * (apply.php). The tools map the content model, security and integrity,
 * nothing else (spec, "Grundsatz"): how Sven writes is up to the prompt.
 */

/** Post types: template => section and the name the Panel shows. */
const BLOG_ASSISTANT_TYPES = [
    'photo' => ['section' => 'photos', 'label' => 'Foto'],
    'trip' => ['section' => 'trips', 'label' => 'Reise'],
    'note' => ['section' => 'notes', 'label' => 'Notiz'],
    'essay' => ['section' => 'essays', 'label' => 'Essay'],
];

/** Post types with a map, and the fields of the map: only propose_route writes them. */
const BLOG_ASSISTANT_MAP_TYPES = ['photo', 'trip'];
const BLOG_ASSISTANT_ROUTE_FIELDS = ['route', 'mappoints', 'mapzoom'];

/** The texts of an image; the camera data (BLOG_EXIF_FIELDS) is not writable. */
const BLOG_ASSISTANT_IMAGE_TEXTS = ['alt', 'caption', 'photographer'];

/** What guard.php checks, for the tool descriptions. */
const BLOG_ASSISTANT_TEXT_RULES = 'Texts: no raw HTML. KirbyText tags only (image: file name), (map:) and (link: page://<uuid> text: …). Links only to URLs from the user\'s messages, from web search results of this chat, or already in the field. Captions: Markdown links by the same rule, otherwise plain text.';

/** Tool definitions for the request. strict: the input always matches the schema. */
function blog_assistant_tools(): array
{
    $str = ['type' => 'string'];
    $num = ['type' => 'number'];
    $bool = ['type' => 'boolean'];
    $object = fn (array $properties) => [
        'type' => 'object',
        'properties' => $properties,
        'required' => array_keys($properties),
        'additionalProperties' => false,
    ];
    $tool = fn (string $name, string $description, array $properties) => [
        'name' => $name,
        'description' => $description,
        'strict' => true,
        'input_schema' => $object($properties),
    ];
    $post = $str + ['description' => 'Post id as find_posts returns it, or "about"'];
    $route = $object([
        'stations' => ['type' => 'array', 'items' => $object(['lat' => $num, 'lon' => $num, 'label' => $str, 'marker' => $bool]), 'description' => 'In order of travel, at least two, routed by car through OSRM. Empty when gpx is set.'],
        'gpx' => $str + ['description' => 'Upload id of a GPX file of this chat, or empty'],
        'markers' => ['type' => 'array', 'items' => $object(['lat' => $num, 'lon' => $num, 'label' => $str]), 'description' => 'Map points besides the stations with marker true'],
        'zoom' => $bool + ['description' => 'Map zoomable'],
    ]) + ['description' => 'Map points: the stations with marker true, then markers. Labels plain text, at most 60 characters.'];
    return [
        $tool('find_posts', 'Lists posts of the four sections, drafts included: newest first, at most 20, each with id, uuid, type, title, date and status, plus the total.', [
            'type' => ['type' => 'string', 'enum' => ['', 'photo', 'trip', 'note', 'essay'], 'description' => 'Empty for all types'],
            'query' => $str + ['description' => 'Text in slug, title, intro or text; empty for all'],
            'from' => $str + ['description' => 'YYYY-MM-DD or empty'],
            'to' => $str + ['description' => 'YYYY-MM-DD or empty'],
        ]),
        $tool('read_post', 'Reads a post or the page about: every field as the Panel shows it (unsaved changes included), the images with their texts and camera data, and for photo and trip the route as km, points and markers.', [
            'post' => $post,
        ]),
        $tool('view_photo', 'Shows an image: a file of a post by its id, or an upload by its upload id. The whole image (x 0, y 0, w 1, h 1) comes as a preview with a long edge of at most 1568 px; a crop shows that part of the original. The preview of an upload is in the message it came with already.', [
            'photo' => $str + ['description' => 'File id from read_post, or upload id'],
            'crop' => $object(['x' => $num, 'y' => $num, 'w' => $num, 'h' => $num]) + ['description' => 'Fractions of width and height'],
        ]),
        $tool('propose_post', 'Proposes a new draft in one step: its fields, photos from uploads of this chat and a route. Nothing changes until the user clicks. The slug must be free in all four sections, drafts included. (image: file name) refers to the file names in photos. A route is possible for photo and trip; no stations and no gpx means no route. ' . BLOG_ASSISTANT_TEXT_RULES, [
            'type' => ['type' => 'string', 'enum' => array_keys(BLOG_ASSISTANT_TYPES)],
            'slug' => $str + ['description' => 'a-z, 0-9 and single hyphens'],
            'date' => $str + ['description' => 'YYYY-MM-DD HH:MM:SS, Europe/Berlin, not in the future'],
            'title' => $str,
            'intro' => $str + ['description' => 'Markdown, field "Anriss"'],
            'text' => $str + ['description' => 'Markdown'],
            'pick' => $bool + ['description' => 'Field "Hervorgehoben"'],
            'trip' => $str + ['description' => 'Field "Reise" of trip posts, else empty'],
            'photos' => ['type' => 'array', 'items' => $object([
                'upload' => $str + ['description' => 'Upload id'],
                'filename' => $str + ['description' => 'Final file name: a-z, 0-9, - and _, with the extension of the upload'],
                'alt' => $str,
                'caption' => $str,
                'photographer' => $str,
            ])],
            'cover' => $str + ['description' => 'One of the file names, or empty'],
            'route' => $route,
        ]),
        $tool('propose_edit', 'Proposes new text for one passage of a text field of a post or of about. The passage must occur exactly once in the field as the Panel shows it. With several versions the user picks one. ' . BLOG_ASSISTANT_TEXT_RULES, [
            'post' => $post,
            'field' => $str,
            'passage' => $str,
            'versions' => ['type' => 'array', 'items' => $str],
        ]),
        $tool('propose_field', 'Proposes a new value for a whole field of a post or of about. Text fields take text; date fields YYYY-MM-DD HH:MM:SS, or YYYY-MM-DD where the field has no time, not in the future; toggles true or false; cover the file name of an image of the post. Route, map points and zoom go through propose_route. ' . BLOG_ASSISTANT_TEXT_RULES, [
            'post' => $post,
            'field' => $str,
            'value' => $str,
        ]),
        $tool('propose_file_fields', 'Proposes new values for the alt text, caption or photographer of an image of a post. Camera data is not writable. ' . BLOG_ASSISTANT_TEXT_RULES, [
            'file' => $str + ['description' => 'File id from read_post'],
            'fields' => ['type' => 'array', 'items' => $object([
                'name' => ['type' => 'string', 'enum' => BLOG_ASSISTANT_IMAGE_TEXTS],
                'value' => $str,
            ])],
        ]),
        $tool('propose_route', 'Proposes a route for a photo or trip post: stations routed by car through OSRM, or a GPX upload of this chat, plus markers and zoom. It replaces route, map points and zoom.', [
            'post' => $post,
            'route' => $route,
        ]),
        $tool('ask_choice', 'Shows a question with buttons. The pick comes back as the next user message.', [
            'question' => $str,
            'options' => ['type' => 'array', 'items' => $str],
        ]),
    ];
}

/** Progress line of a read tool, as the Panel shows it while the next round runs. */
function blog_assistant_progress(string $tool): ?string
{
    return [
        'find_posts' => 'Beiträge gesucht',
        'read_post' => 'Beitrag gelesen',
        'view_photo' => 'Foto angesehen',
    ][$tool] ?? null;
}

/** A page by its id, drafts on every level included ("photos/aika"), or by its UUID. */
function blog_assistant_page(string $id): ?Page
{
    $id = trim($id, " /");
    if (str_starts_with($id, 'page://')) {
        return blog_guard_page($id);
    }
    $page = null;
    foreach ($id === '' ? [] : explode('/', $id) as $slug) {
        $page = ($page ?? site())->findPageOrDraft($slug);
        if ($page === null) {
            return null;
        }
    }
    return $page;
}

/** A post of the four sections, or About; null for any other page. */
function blog_assistant_find(string $ref): ?Page
{
    $page = blog_assistant_page($ref);
    $template = $page?->intendedTemplate()->name() ?? '';
    return $template === 'about' || isset(BLOG_ASSISTANT_TYPES[$template]) ? $page : null;
}

/** A file of a post or of About by its id ("photos/aika/IMG_1.jpg"). */
function blog_assistant_file(string $id): ?File
{
    $page = blog_assistant_find(dirname(trim($id)));
    return $page?->file(basename(trim($id)));
}

/** A file of a page by its UUID ("file://…") or file name. */
function blog_assistant_file_ref(Page $page, string $ref): ?File
{
    return $page->files()->filter(fn (File $file) => $file->uuid()?->toString() === $ref || $file->filename() === $ref)->first();
}

/** A YAML list as a field stores it (files, structure), or none. */
function blog_assistant_list(string $yaml): array
{
    $list = trim($yaml) === '' ? [] : Yaml::decode($yaml);
    return is_array($list) ? array_values($list) : [];
}

/** The fields the assistant reads and writes: name => [label, type]. The title first. */
function blog_assistant_fields(ModelWithContent $model): array
{
    $fields = $model instanceof Page ? ['title' => ['label' => 'Titel', 'type' => 'text']] : [];
    foreach ($model->blueprint()->fields() as $name => $field) {
        $label = $field['label'] ?? null;
        $fields[strtolower($name)] = ['label' => is_string($label) ? $label : ucfirst($name), 'type' => (string)($field['type'] ?? 'text')];
    }
    return $fields;
}

/** A field's value as the Panel shows it: the unsaved changes if there are any. */
function blog_assistant_current(ModelWithContent $model, string $field): string
{
    $changes = $model->version('changes');
    $source = $changes->exists() ? $changes : $model->version('latest');
    return (string)($source->read()[strtolower($field)] ?? '');
}

/** True when the field has unsaved changes in the Panel. */
function blog_assistant_unsaved(ModelWithContent $model, string $field): bool
{
    $changes = $model->version('changes');
    return $changes->exists() && blog_assistant_current($model, $field) !== (string)($model->version('latest')->read()[strtolower($field)] ?? '');
}

/** The image file names of a post. */
function blog_assistant_filenames(Page $page): array
{
    return array_values($page->files()->template('image')->pluck('filename'));
}

/**
 * The route of a photo or trip as the Panel shows it: km, points, markers,
 * zoom. "hash" covers file, points and zoom as stored: a route card
 * compares it at the click.
 */
function blog_assistant_route_summary(Page $page): ?array
{
    if (!in_array($page->intendedTemplate()->name(), BLOG_ASSISTANT_MAP_TYPES, true)) {
        return null;
    }
    $refs = blog_assistant_list(blog_assistant_current($page, 'route'));
    $file = isset($refs[0]) ? blog_assistant_file_ref($page, (string)$refs[0]) : null;
    $json = $file !== null ? (string)file_get_contents($file->root()) : '';
    $points = blog_assistant_current($page, 'mappoints');
    $zoom = blog_assistant_current($page, 'mapzoom');
    $line = blog_route_line($json);
    return [
        'file' => $file?->filename(),
        'km' => round(blog_route_length($line) / 1000, 1),
        'seconds' => null,
        'points' => count($line),
        'markers' => array_map(fn ($p) => ['label' => (string)($p['label'] ?? ''), 'lat' => (float)($p['lat'] ?? 0), 'lon' => (float)($p['lon'] ?? 0)], blog_assistant_list($points)),
        'zoom' => $zoom === 'true',
        'hash' => sha1($json . "\0" . $points . "\0" . $zoom),
    ];
}

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
    return isset(BLOG_ASSISTANT_TYPES[$template])
        ? BLOG_ASSISTANT_TYPES[$template]['label'] . ' „' . $model->title()->value() . '“'
        : $model->title()->value();
}

/** Posts by type, text and date (find_posts). */
function blog_assistant_find_posts(array $input): array
{
    $type = (string)$input['type'];
    $query = mb_strtolower(trim((string)$input['query']));
    $from = trim((string)$input['from']);
    $to = trim((string)$input['to']);
    $found = [];
    foreach (BLOG_ASSISTANT_TYPES as $template => $info) {
        if ($type !== '' && $type !== $template) {
            continue;
        }
        foreach (site()->find($info['section'])?->childrenAndDrafts() ?? [] as $page) {
            if ($page->intendedTemplate()->name() !== $template) {
                continue;
            }
            $date = blog_assistant_current($page, 'date');
            $day = substr($date, 0, 10);
            if (($from !== '' && $day < $from) || ($to !== '' && $day > $to)) {
                continue;
            }
            $text = implode("\n", [$page->slug(), ...array_map(fn ($f) => blog_assistant_current($page, $f), ['title', 'intro', 'text'])]);
            if ($query !== '' && !str_contains(mb_strtolower($text), $query)) {
                continue;
            }
            $found[] = [
                'id' => $page->id(),
                'uuid' => $page->uuid()?->toString(),
                'type' => $template,
                'title' => blog_assistant_current($page, 'title'),
                'date' => $date,
                'status' => $page->isDraft() ? 'draft' : 'published',
            ];
        }
    }
    usort($found, fn ($a, $b) => strcmp($b['date'], $a['date']));
    return ['total' => count($found), 'posts' => array_slice($found, 0, 20)];
}

/** A post or About for read_post. */
function blog_assistant_post_data(Page $page): array
{
    $fields = [];
    foreach (blog_assistant_fields($page) as $name => $field) {
        if (in_array($name, BLOG_ASSISTANT_ROUTE_FIELDS, true)) {
            continue;
        }
        $value = blog_assistant_current($page, $name);
        if ($field['type'] === 'files') {
            $value = array_map(fn ($ref) => blog_assistant_file_ref($page, (string)$ref)?->filename() ?? (string)$ref, blog_assistant_list($value));
        }
        $fields[$name] = ['label' => $field['label'], 'type' => $field['type'], 'value' => $value];
    }
    $files = [];
    foreach ($page->files()->template('image')->sorted() as $file) {
        $texts = [];
        foreach (BLOG_ASSISTANT_IMAGE_TEXTS as $key) {
            $texts[$key] = blog_assistant_current($file, $key);
        }
        $camera = [];
        foreach (BLOG_EXIF_FIELDS as $key) {
            $value = (string)$file->content()->get($key)->value();
            if ($value !== '') {
                $camera[$key] = $value;
            }
        }
        $files[] = ['id' => $file->id(), 'filename' => $file->filename(), 'texts' => $texts, 'camera' => $camera];
    }
    $route = blog_assistant_route_summary($page);
    if ($route !== null) {
        unset($route['hash'], $route['seconds']);
    }
    return [
        'id' => $page->id(),
        'uuid' => $page->uuid()?->toString(),
        'type' => $page->intendedTemplate()->name(),
        'status' => $page->isDraft() ? 'draft' : 'published',
        'unsaved' => $page->version('changes')->exists(),
        'fields' => $fields,
        'files' => $files,
        'route' => $route,
    ];
}

/**
 * view_photo: a preview or a crop as an image block, with the facts as
 * text. $load: the images the next request carries already
 * (blog_assistant_image_load); a chat takes 40, and no more bytes than a
 * request can carry.
 */
function blog_assistant_view_photo(array $input, array $load): array
{
    $ref = trim((string)$input['photo']);
    $crop = $input['crop'];
    $whole = (float)$crop['x'] <= 0 && (float)$crop['y'] <= 0 && (float)$crop['w'] >= 1 && (float)$crop['h'] >= 1;
    $text = fn (array $data) => ['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    $full = ['error' => 'This chat holds ' . BLOG_ASSISTANT_IMAGES . ' images, as many as it takes. view_photo shows no more; the user can start a new chat.'];
    $heavy = ['error' => 'The images of this chat would make a request to Claude too large. view_photo shows no more; the user can start a new chat.'];
    if ($load['count'] >= BLOG_ASSISTANT_IMAGES) {
        return [$text($full)];
    }
    // The image is made first: its size decides. One that does not fit goes again.
    $fits = function (string $name) use ($load): bool {
        if ($load['bytes'] + blog_uploads_base64(blog_uploads_size($name)) <= BLOG_ASSISTANT_IMAGE_BYTES) {
            return true;
        }
        F::remove(blog_uploads_dir() . '/' . $name);
        return false;
    };
    if (preg_match(BLOG_UPLOAD_ID, $ref)) {
        $meta = blog_uploads_meta($ref);
        if ($meta === null || $meta['kind'] !== 'image' || $meta['path'] === null) {
            return [$text(['error' => 'No image upload with this id in this chat.'])];
        }
        if ($whole) {
            return [$text(['note' => 'The preview of this upload is in the message it came with. A crop shows a part of the original.'])];
        }
        $name = blog_uploads_image($meta['path'], $meta['orientation'], $crop);
        return $fits($name) ? [['type' => 'blog_image', 'file' => $name], $text(['upload' => $ref, 'crop' => $crop])] : [$text($heavy)];
    }
    $file = blog_assistant_file($ref);
    if ($file === null || $file->template() !== 'image') {
        return [$text(['error' => 'No image found. read_post lists the file ids.'])];
    }
    $orientation = $file->extension() === 'jpg' || $file->extension() === 'jpeg' ? (int)((@exif_read_data($file->root()) ?: [])['Orientation'] ?? 1) : 1;
    $name = blog_uploads_image($file->root(), $orientation, $whole ? null : $crop);
    return $fits($name) ? [
        ['type' => 'blog_image', 'file' => $name],
        $text(['id' => $file->id(), 'filename' => $file->filename()] + ($whole ? [] : ['crop' => $crop])),
    ] : [$text($heavy)];
}

/**
 * Runs a read tool. Returns the tool_result content: text, or an image
 * with text. $load: the images the next request carries already.
 */
function blog_assistant_read(string $name, array $input, array $load = ['count' => 0, 'bytes' => 0]): array
{
    $json = fn ($data) => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]];
    switch ($name) {
        case 'find_posts':
            return $json(blog_assistant_find_posts($input));
        case 'read_post':
            $page = blog_assistant_find((string)$input['post']);
            return $json($page === null ? ['error' => 'No post found. find_posts lists the ids; the page about is "about".'] : blog_assistant_post_data($page));
        case 'view_photo':
            return blog_assistant_view_photo($input, $load);
    }
    return $json(['error' => 'Unknown tool']);
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
        'files' => implode(', ', array_map(fn ($ref) => blog_assistant_file_ref($page, (string)$ref)?->filename() ?? (string)$ref, blog_assistant_list($value))),
        default => $value,
    };
}

/** propose_field: a whole field of a post or of About. */
function blog_assistant_propose_field(array $chat, array $input): array|string
{
    $page = blog_assistant_find((string)$input['post']);
    if ($page === null) {
        return 'No post found. find_posts lists the ids; the page about is "about".';
    }
    $fields = array_diff_key(blog_assistant_fields($page), array_flip(BLOG_ASSISTANT_ROUTE_FIELDS));
    $name = blog_assistant_field_name($fields, (string)$input['field']);
    if ($name === null) {
        return 'This field cannot be written: ' . $input['field'] . '. Fields: ' . implode(', ', array_keys($fields)) . '. Route, map points and zoom go through propose_route.';
    }
    $type = $fields[$name]['type'];
    $value = (string)$input['value'];
    $before = blog_assistant_current($page, $name);
    $stored = $value;
    switch ($type) {
        case 'date':
            $error = blog_assistant_date_error($value, (bool)($page->blueprint()->field($name)['time'] ?? false));
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
        // Label, before, after, and the links the card marks in both.
        'diff' => [[$fields[$name]['label'], blog_assistant_shown($page, $type, $before), blog_assistant_shown($page, $type, $stored), blog_assistant_marks($page, $type, $before), blog_assistant_marks($page, $type, $stored)]],
        'input' => ['page' => $page->id(), 'field' => $name, 'value' => $stored, 'before' => $before],
    ]);
}

/** propose_edit: one passage of a text field, in one or more versions. */
function blog_assistant_propose_edit(array $chat, array $input): array|string
{
    $page = blog_assistant_find((string)$input['post']);
    if ($page === null) {
        return 'No post found. find_posts lists the ids; the page about is "about".';
    }
    $fields = array_filter(blog_assistant_fields($page), fn ($f) => in_array($f['type'], ['text', 'textarea', 'markdown'], true));
    $name = blog_assistant_field_name($fields, (string)$input['field']);
    if ($name === null) {
        return 'Passages exist in text fields only: ' . implode(', ', array_keys($fields)) . '.';
    }
    $passage = (string)$input['passage'];
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
        $marks[] = array_values(array_filter(blog_assistant_marks($page, $type, $after), fn ($mark) => str_contains($version, $mark)));
    }
    return blog_assistant_card('edit', 'Passage · ' . blog_assistant_name($page), [
        'where' => blog_assistant_where($page),
        'unsaved' => blog_assistant_unsaved($page, $name),
        'field' => $fields[$name]['label'],
        'passage' => $passage,
        'versions' => $versions,
        // The links the card marks in the passage and in each version.
        'passageMarks' => array_values(array_filter(blog_assistant_marks($page, $type, $current), fn ($mark) => str_contains($passage, $mark))),
        'versionMarks' => $marks,
        'input' => ['page' => $page->id(), 'field' => $name, 'passage' => $passage, 'versions' => $versions],
    ]);
}

/** propose_file_fields: alt text, caption or photographer of an image. */
function blog_assistant_propose_file_fields(array $chat, array $input): array|string
{
    $file = blog_assistant_file((string)$input['file']);
    if ($file === null || $file->template() !== 'image') {
        return 'No image found. read_post lists the file ids.';
    }
    $changes = [];
    $diff = [];
    foreach ($input['fields'] as $field) {
        $name = (string)$field['name'];
        $value = (string)$field['value'];
        if (!in_array($name, BLOG_ASSISTANT_IMAGE_TEXTS, true) || isset($changes[$name])) {
            return "Each of alt, caption and photographer at most once; $name is not possible here.";
        }
        $before = blog_assistant_current($file, $name);
        $error = $name === 'caption' ? blog_guard_caption($value, blog_assistant_links($chat), $before) : blog_guard_plain($value);
        if ($error !== null) {
            return "$name: $error";
        }
        $changes[$name] = ['before' => $before, 'after' => $value];
        $marks = fn (string $text) => $name === 'caption' ? blog_guard_marks($text, null, true) : [];
        $diff[] = [(string)($file->blueprint()->field($name)['label'] ?? $name), $before, $value, $marks($before), $marks($value)];
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
    $lat = (float)$point['lat'];
    $lon = (float)$point['lon'];
    return abs($lat) <= 90 && abs($lon) <= 180 ? [$lon, $lat] : null;
}

/**
 * The route of a card: stations through OSRM, or a GPX upload of this
 * chat, plus markers and zoom. Returns the route, or the error for Claude.
 */
function blog_assistant_route(array $route): array|string
{
    $stations = $route['stations'];
    $gpx = trim((string)$route['gpx']);
    if (($stations === []) === ($gpx === '')) {
        return 'A route takes either stations or a GPX upload id.';
    }
    $markers = [];
    foreach ([...array_filter($stations, fn ($s) => $s['marker']), ...$route['markers']] as $marker) {
        $point = blog_assistant_point($marker);
        if ($point === null) {
            return 'A marker lies outside the map: lat -90 to 90, lon -180 to 180.';
        }
        $error = blog_guard_plain((string)$marker['label'], 60);
        if ($error !== null) {
            return 'Marker "' . $marker['label'] . '": ' . $error;
        }
        $markers[] = ['label' => (string)$marker['label'], 'lon' => $point[0], 'lat' => $point[1]];
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
                    return 'The marker "' . $marker['label'] . '" lies closer than 1200 m to an end of the ride. Markers there are not possible.';
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
    return $line + ['points' => count($line['line']), 'markers' => $markers, 'zoom' => (bool)$route['zoom']];
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
        'Punkte' => (string)$route['points'],
        'Marker' => implode(', ', array_column($route['markers'], 'label')),
        'Karte zoombar' => $route['zoom'] ? 'ja' : 'nein',
    ];
}

/** propose_route: a new route for a photo or trip, replacing route, points and zoom. */
function blog_assistant_propose_route(array $chat, array $input): array|string
{
    $page = blog_assistant_find((string)$input['post']);
    if ($page === null || !in_array($page->intendedTemplate()->name(), BLOG_ASSISTANT_MAP_TYPES, true)) {
        return 'A route is possible for photo and trip posts only. find_posts lists the ids.';
    }
    $route = blog_assistant_route($input['route']);
    if (is_string($route)) {
        return $route;
    }
    if ($route['upload'] !== null && in_array($route['upload'], blog_assistant_needed($chat), true)) {
        return 'Another open card uses this GPX upload already.';
    }
    $before = blog_assistant_route_summary($page);
    $old = blog_assistant_route_rows($before['points'] > 0 || $before['markers'] !== [] ? $before : null);
    $new = blog_assistant_route_rows($route);
    return blog_assistant_card('route', 'Route · ' . blog_assistant_name($page), [
        'where' => blog_assistant_where($page),
        'uploads' => array_filter([$route['upload']]),
        'diff' => array_map(fn ($label) => [$label, $old[$label], $new[$label]], array_keys($new)),
        'input' => ['page' => $page->id(), 'route' => $route, 'hash' => $before['hash']],
    ]);
}

/**
 * The fields of the post blueprints and what fills them in propose_post.
 * A field missing here fails AssistantPostTest: the tool must learn it.
 */
const BLOG_ASSISTANT_POST_FIELDS = [
    'intro' => 'intro', 'text' => 'text', 'date' => 'date', 'cover' => 'cover', 'pick' => 'pick',
    'trip' => 'trip', 'route' => 'route', 'mappoints' => 'route', 'mapzoom' => 'route',
];

/** The page that uses a slug in one of the four sections or at the top, drafts included, or null. */
function blog_assistant_slug_taken(string $slug): ?string
{
    foreach (BLOG_ASSISTANT_TYPES as $info) {
        $page = site()->find($info['section'])?->childrenAndDrafts()->findBy('slug', $slug);
        if ($page !== null) {
            return $page->id();
        }
    }
    return site()->childrenAndDrafts()->findBy('slug', $slug)?->id();
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
        'files' => array_map(fn ($p) => ['filename' => $p['filename'], 'template' => 'image', 'content' => ['alt' => $p['alt'], 'caption' => $p['caption']]], $photos),
    ]);
}

/** propose_post: a new draft with fields, photos and route in one click. */
function blog_assistant_propose_post(array $chat, array $input): array|string
{
    $type = (string)$input['type'];
    if (!isset(BLOG_ASSISTANT_TYPES[$type]) || site()->find(BLOG_ASSISTANT_TYPES[$type]['section']) === null) {
        return 'No section for the type ' . $type . '.';
    }
    $slug = (string)$input['slug'];
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
        return 'A slug takes a-z, 0-9 and single hyphens.';
    }
    if (($taken = blog_assistant_slug_taken($slug)) !== null) {
        return "The slug $slug is taken by $taken. The URL must be unique.";
    }
    $date = (string)$input['date'];
    if (($error = blog_assistant_date_error($date)) !== null) {
        return $error;
    }
    $title = trim((string)$input['title']);
    if ($title === '' || ($error = blog_guard_plain($title)) !== null) {
        return 'title: ' . ($error ?? 'A post needs a title.');
    }
    $trip = trim((string)$input['trip']);
    if ($trip !== '' && ($type !== 'trip' || ($error = blog_guard_plain($trip)) !== null)) {
        return 'trip: ' . ($error ?? 'only trip posts have this field.');
    }
    $needed = blog_assistant_needed($chat);
    $allowed = blog_assistant_links($chat);
    $photos = [];
    foreach ($input['photos'] as $photo) {
        $id = (string)$photo['upload'];
        $filename = (string)$photo['filename'];
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
            if (($error = blog_guard_plain((string)$photo[$key])) !== null) {
                return "$filename, $key: $error";
            }
        }
        if (($error = blog_guard_caption((string)$photo['caption'], $allowed)) !== null) {
            return "$filename, caption: $error";
        }
        $photos[] = ['upload' => $id, 'filename' => $filename, 'alt' => (string)$photo['alt'], 'caption' => (string)$photo['caption'], 'photographer' => (string)$photo['photographer'], 'exif' => $meta['exif'], 'marks' => blog_guard_marks((string)$photo['caption'], null, true)];
    }
    $names = array_column($photos, 'filename');
    $cover = (string)$input['cover'];
    if ($cover !== '' && !in_array($cover, $names, true)) {
        return 'cover is one of the file names: ' . implode(', ', $names) . '.';
    }
    $map = in_array($type, BLOG_ASSISTANT_MAP_TYPES, true);
    // The texts render in the draft as it will be: its images, its map.
    $draft = blog_assistant_draft(site()->find(BLOG_ASSISTANT_TYPES[$type]['section']), $type, $slug, $photos);
    foreach (['intro', 'text'] as $key) {
        if (($error = blog_guard_markdown((string)$input[$key], $allowed, $draft)) !== null) {
            return "$key: $error";
        }
    }
    $route = null;
    if ($input['route']['stations'] !== [] || trim((string)$input['route']['gpx']) !== '') {
        if (!$map) {
            return 'A route is possible for photo and trip posts only.';
        }
        $route = blog_assistant_route($input['route']);
        if (is_string($route)) {
            return "route: $route";
        }
        if ($route['upload'] !== null && in_array($route['upload'], $needed, true)) {
            return 'Another open card uses this GPX upload already.';
        }
    }
    $content = ['title' => $title, 'date' => $date, 'intro' => (string)$input['intro'], 'text' => (string)$input['text'], 'pick' => $input['pick'] ? 'true' : 'false'] + ($type === 'trip' ? ['trip' => $trip] : []);
    $rows = [
        ['Titel', $title],
        ['URL-Anhang', $slug],
        ['Datum', $date],
        ['Anriss', $content['intro'], blog_guard_marks($content['intro'], $draft)],
        ['Text', $content['text'], blog_guard_marks($content['text'], $draft)],
        ['Titelbild', $cover],
        ['Hervorgehoben', $input['pick'] ? 'ja' : 'nein'],
        ...($type === 'trip' ? [['Reise', $trip]] : []),
    ];
    foreach ($route === null ? [] : blog_assistant_route_rows($route) as $label => $value) {
        $rows[] = [$label, $value];
    }
    return blog_assistant_card('post', 'Neuer Entwurf · ' . BLOG_ASSISTANT_TYPES[$type]['label'], [
        'where' => 'Anlegen legt einen Entwurf an. Veröffentlichen bleibt bei dir.',
        'uploads' => [...array_column($photos, 'upload'), ...array_filter([$route['upload'] ?? null])],
        'rows' => $rows,
        'photos' => array_map(fn ($p) => array_diff_key($p, ['exif' => true]), $photos),
        'input' => ['type' => $type, 'slug' => $slug, 'content' => $content, 'photos' => array_map(fn ($p) => array_diff_key($p, ['marks' => true]), $photos), 'cover' => $cover, 'route' => $route],
    ]);
}
