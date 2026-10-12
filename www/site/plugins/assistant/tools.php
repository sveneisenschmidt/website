<?php

use Kirby\Cms\File;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use Kirby\Data\Yaml;

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

/** view_photo: a preview or a crop as an image block, with the facts as text. */
function blog_assistant_view_photo(array $input): array
{
    $ref = trim((string)$input['photo']);
    $crop = $input['crop'];
    $whole = (float)$crop['x'] <= 0 && (float)$crop['y'] <= 0 && (float)$crop['w'] >= 1 && (float)$crop['h'] >= 1;
    $text = fn (array $data) => ['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    if (preg_match(BLOG_UPLOAD_ID, $ref)) {
        $meta = blog_uploads_meta($ref);
        if ($meta === null || $meta['kind'] !== 'image' || $meta['path'] === null) {
            return [$text(['error' => 'No image upload with this id in this chat.'])];
        }
        if ($whole) {
            return [$text(['note' => 'The preview of this upload is in the message it came with. A crop shows a part of the original.'])];
        }
        return [['type' => 'blog_image', 'file' => blog_uploads_image($meta['path'], $meta['orientation'], $crop)], $text(['upload' => $ref, 'crop' => $crop])];
    }
    $file = blog_assistant_file($ref);
    if ($file === null || $file->template() !== 'image') {
        return [$text(['error' => 'No image found. read_post lists the file ids.'])];
    }
    $orientation = $file->extension() === 'jpg' || $file->extension() === 'jpeg' ? (int)((@exif_read_data($file->root()) ?: [])['Orientation'] ?? 1) : 1;
    return [
        ['type' => 'blog_image', 'file' => blog_uploads_image($file->root(), $orientation, $whole ? null : $crop)],
        $text(['id' => $file->id(), 'filename' => $file->filename()] + ($whole ? [] : ['crop' => $crop])),
    ];
}

/** Runs a read tool. Returns the tool_result content: text, or an image with text. */
function blog_assistant_read(string $name, array $input): array
{
    $json = fn ($data) => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]];
    switch ($name) {
        case 'find_posts':
            return $json(blog_assistant_find_posts($input));
        case 'read_post':
            $page = blog_assistant_find((string)$input['post']);
            return $json($page === null ? ['error' => 'No post found. find_posts lists the ids; the page about is "about".'] : blog_assistant_post_data($page));
        case 'view_photo':
            return blog_assistant_view_photo($input);
    }
    return $json(['error' => 'Unknown tool']);
}

/**
 * Turns a propose tool call into a card. Returns the proposal, or the
 * error Claude gets back instead.
 */
function blog_assistant_propose(array $chat, string $name, array $input): array|string
{
    return match ($name) {
        default => 'Unknown tool: ' . $name,
    };
}
