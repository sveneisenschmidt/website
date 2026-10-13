<?php

namespace Website\Assistant;

use Kirby\Cms\Page;
use Kirby\Filesystem\F;
use Website\Blog\Exif;
use Website\Blog\Types;

/**
 * The tools Claude may call, and the read tools, which run at once. Propose
 * tools (Proposals) change nothing: they return a card, and only Sven's
 * click applies it (Apply). The tools map the content model, security and
 * integrity, nothing else: how Sven writes is up to the prompt.
 */
final class Tools
{
    /** The fields of a map (post types with Types::hasMap): only propose_route writes them. */
    public const ROUTE_FIELDS = ['route', 'mappoints', 'mapzoom'];

    /** The texts of an image; the camera data (Exif::FIELDS) is not writable. */
    public const IMAGE_TEXTS = ['alt', 'caption', 'photographer'];

    /** The answer of a proposal whose GPX upload another open card holds. */
    public const GPX_TAKEN = 'Another open card uses this GPX upload already.';

    /** The answer of a tool for a post id that matches nothing. */
    public const NO_POST = 'No post found. find_posts lists the ids; the page about is "about".';

    /** Posts find_posts lists at most. */
    public const FIND_LIMIT = 20;

    /** What Guard checks, for the tool descriptions. */
    public const TEXT_RULES = 'Texts: no raw HTML. KirbyText tags only (image: file name), (map:) and (link: page://<uuid> text: …). Links only to URLs from the user\'s messages, from web search results of this chat, or already in the field. Captions: Markdown links by the same rule, otherwise plain text.';

    /**
     * Tool definitions for the request. Not strict: with all tools strict the API
     * refuses the request ("compiled grammar is too large"); the propose and read
     * tools check every input themselves. A chat freezes them (ChatState::$tools):
     * a change here reaches new chats only.
     *
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        $str = ['type' => 'string'];
        $num = ['type' => 'number'];
        $bool = ['type' => 'boolean'];
        $object = fn(array $properties) => [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
        $tool = fn(string $name, string $description, array $properties) => [
            'name' => $name,
            'description' => $description,
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
            $tool('find_posts', 'Lists posts of the four sections, drafts included: newest first, at most ' . self::FIND_LIMIT . ', each with id, uuid, type, title, date and status, plus the total.', [
                'type' => ['type' => 'string', 'enum' => ['', ...array_keys(Types::ALL)], 'description' => 'Empty for all types'],
                'query' => $str + ['description' => 'Text in slug, title, intro or text; empty for all'],
                'from' => $str + ['description' => 'YYYY-MM-DD or empty'],
                'to' => $str + ['description' => 'YYYY-MM-DD or empty'],
            ]),
            $tool('read_post', 'Reads a post or the page about: every field as the Panel shows it (unsaved changes included), the images with their texts and camera data, and for photo and trip the route as km, points and markers.', [
                'post' => $post,
            ]),
            $tool('view_photo', 'Shows an image: a file of a post by its id, or an upload by its upload id. The whole image (x 0, y 0, w 1, h 1) comes as a preview with a long edge of at most ' . Uploads::PREVIEW . ' px; a crop shows that part of the original. The preview of an upload is in the message it came with already.', [
                'photo' => $str + ['description' => 'File id from read_post, or upload id'],
                'crop' => $object(['x' => $num, 'y' => $num, 'w' => $num, 'h' => $num]) + ['description' => 'Fractions of width and height'],
            ]),
            $tool('propose_post', 'Proposes a new draft in one step: its fields, photos from uploads of this chat and a route. Nothing changes until the user clicks. The slug must be free in all four sections, drafts included. (image: file name) refers to the file names in photos. A route is possible for photo and trip; no stations and no gpx means no route. ' . self::TEXT_RULES, [
                'type' => ['type' => 'string', 'enum' => array_keys(Types::ALL)],
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
            $tool('propose_edit', 'Proposes new text for one passage of a text field of a post or of about. The passage must occur exactly once in the field as the Panel shows it. With several versions the user picks one. ' . self::TEXT_RULES, [
                'post' => $post,
                'field' => $str,
                'passage' => $str,
                'versions' => ['type' => 'array', 'items' => $str],
            ]),
            $tool('propose_field', 'Proposes a new value for a whole field of a post or of about. Text fields take text; date fields YYYY-MM-DD HH:MM:SS, or YYYY-MM-DD where the field has no time, not in the future; toggles true or false; cover the file name of an image of the post. Route, map points and zoom go through propose_route. ' . self::TEXT_RULES, [
                'post' => $post,
                'field' => $str,
                'value' => $str,
            ]),
            $tool('propose_file_fields', 'Proposes new values for the alt text, caption or photographer of an image of a post. Camera data is not writable. ' . self::TEXT_RULES, [
                'file' => $str + ['description' => 'File id from read_post'],
                'fields' => ['type' => 'array', 'items' => $object([
                    'name' => ['type' => 'string', 'enum' => self::IMAGE_TEXTS],
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
    public static function progress(string $tool): ?string
    {
        return [
            'find_posts' => 'Beiträge gesucht',
            'read_post' => 'Beitrag gelesen',
            'view_photo' => 'Foto angesehen',
        ][$tool] ?? null;
    }

    /**
     * Runs a read tool. Returns the tool_result content: text, or an image
     * with text. $load: the images the next request carries already.
     *
     * @param array<string, mixed> $input
     * @param array{count: int, bytes: int} $load
     * @return list<array<string, mixed>>
     */
    public static function read(string $name, array $input, array $load = ['count' => 0, 'bytes' => 0]): array
    {
        $json = fn($data) => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]];
        switch ($name) {
            case 'find_posts':
                return $json(self::findPosts($input));
            case 'read_post':
                $page = Content::find((string) $input['post']);
                return $json($page === null ? ['error' => self::NO_POST] : self::postData($page));
            case 'view_photo':
                return self::viewPhoto($input, $load);
        }
        return $json(['error' => 'Unknown tool']);
    }

    /**
     * Posts by type, text and date (find_posts).
     *
     * @param array<string, mixed> $input
     * @return array{total: int, posts: list<array<string, string>>}
     */
    private static function findPosts(array $input): array
    {
        $type = (string) $input['type'];
        $query = mb_strtolower(trim((string) $input['query']));
        $from = trim((string) $input['from']);
        $to = trim((string) $input['to']);
        $found = [];
        foreach (Types::ALL as $template => $info) {
            if ($type !== '' && $type !== $template) {
                continue;
            }
            foreach (site()->find($info['section'])?->childrenAndDrafts() ?? [] as $page) {
                if ($page->intendedTemplate()->name() !== $template) {
                    continue;
                }
                $date = Content::current($page, 'date');
                $day = substr($date, 0, 10);
                if (($from !== '' && $day < $from) || ($to !== '' && $day > $to)) {
                    continue;
                }
                $text = implode("\n", [$page->slug(), ...array_map(fn($f) => Content::current($page, $f), ['title', 'intro', 'text'])]);
                if ($query !== '' && !str_contains(mb_strtolower($text), $query)) {
                    continue;
                }
                $found[] = [
                    'id' => $page->id(),
                    'uuid' => $page->uuid()->toString(),
                    'type' => $template,
                    'title' => Content::current($page, 'title'),
                    'date' => $date,
                    'status' => $page->isDraft() ? 'draft' : 'published',
                ];
            }
        }
        usort($found, fn($a, $b) => strcmp($b['date'], $a['date']));
        return ['total' => count($found), 'posts' => array_slice($found, 0, self::FIND_LIMIT)];
    }

    /**
     * A post or About for read_post.
     *
     * @return array<string, mixed>
     */
    private static function postData(Page $page): array
    {
        $fields = [];
        foreach (Content::fields($page) as $name => $field) {
            if (in_array($name, self::ROUTE_FIELDS, true)) {
                continue;
            }
            $value = Content::current($page, $name);
            if ($field['type'] === 'files') {
                $value = array_map(fn($ref) => Content::fileRef($page, (string) $ref)?->filename() ?? (string) $ref, Content::list($value));
            }
            $fields[$name] = ['label' => $field['label'], 'type' => $field['type'], 'value' => $value];
        }
        $files = [];
        foreach ($page->files()->template('image')->sorted() as $file) {
            $texts = [];
            foreach (self::IMAGE_TEXTS as $key) {
                $texts[$key] = Content::current($file, $key);
            }
            $camera = [];
            foreach (Exif::FIELDS as $key) {
                $value = (string) $file->content()->get($key)->value();
                if ($value !== '') {
                    $camera[$key] = $value;
                }
            }
            $files[] = ['id' => $file->id(), 'filename' => $file->filename(), 'texts' => $texts, 'camera' => $camera];
        }
        $route = Content::routeSummary($page);
        if ($route !== null) {
            unset($route['hash'], $route['seconds']);
        }
        return [
            'id' => $page->id(),
            'uuid' => $page->uuid()->toString(),
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
     * (Chat::imageLoad); a chat takes 40, and no more bytes than a request
     * can carry.
     *
     * @param array<string, mixed> $input
     * @param array{count: int, bytes: int} $load
     * @return list<array<string, mixed>>
     */
    private static function viewPhoto(array $input, array $load): array
    {
        $ref = trim((string) $input['photo']);
        $crop = $input['crop'];
        $whole = (float) $crop['x'] <= 0 && (float) $crop['y'] <= 0 && (float) $crop['w'] >= 1 && (float) $crop['h'] >= 1;
        $text = fn(array $data) => ['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        $full = ['error' => 'This chat holds ' . Chat::IMAGES . ' images, as many as it takes. view_photo shows no more; the user can start a new chat.'];
        $heavy = ['error' => 'The images of this chat would make a request to Claude too large. view_photo shows no more; the user can start a new chat.'];
        if ($load['count'] >= Chat::IMAGES) {
            return [$text($full)];
        }
        // The image is made first: its size decides. One that does not fit goes again.
        $fits = function (string $name) use ($load): bool {
            if ($load['bytes'] + Uploads::base64(Uploads::size($name)) <= Chat::IMAGE_BYTES) {
                return true;
            }
            F::remove(Uploads::dir() . '/' . $name);
            return false;
        };
        if (preg_match(Uploads::ID, $ref)) {
            $meta = Uploads::meta($ref);
            if ($meta === null || $meta['kind'] !== 'image' || $meta['path'] === null) {
                return [$text(['error' => 'No image upload with this id in this chat.'])];
            }
            if ($whole) {
                return [$text(['note' => 'The preview of this upload is in the message it came with. A crop shows a part of the original.'])];
            }
            $name = Uploads::image($meta['path'], $meta['orientation'], $crop);
            return $fits($name) ? [['type' => 'blog_image', 'file' => $name], $text(['upload' => $ref, 'crop' => $crop])] : [$text($heavy)];
        }
        $file = Content::file($ref);
        if ($file === null || $file->template() !== 'image') {
            return [$text(['error' => 'No image found. read_post lists the file ids.'])];
        }
        $orientation = $file->extension() === 'jpg' || $file->extension() === 'jpeg' ? (int) ((@exif_read_data($file->root()) ?: [])['Orientation'] ?? 1) : 1;
        $name = Uploads::image($file->root(), $orientation, $whole ? null : $crop);
        return $fits($name) ? [
            ['type' => 'blog_image', 'file' => $name],
            $text(['id' => $file->id(), 'filename' => $file->filename()] + ($whole ? [] : ['crop' => $crop])),
        ] : [$text($heavy)];
    }
}
