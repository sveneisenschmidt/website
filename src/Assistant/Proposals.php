<?php

namespace Website\Assistant;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Kirby\Cms\File;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use Kirby\Data\Yaml;
use Kirby\Filesystem\F;
use Parsedown;
use ParsedownExtra;
use RuntimeException;
use Website\Blog\Types;
use Website\Models\PostPage;

/**
 * Runs the propose tools, the tools whose names start with `propose_`. Each
 * turns a tool call into a card, or into an error message that Claude gets
 * back. A card is a change that Claude proposed; the chat shows it with
 * buttons to apply or discard it. This class changes no content; only a
 * click of the Panel user on a card does (`Apply`). The input of a card
 * names pages and files by their UUIDs, so an open card still finds its
 * post after a new slug (`slug()`).
 */
final class Proposals
{
    /**
     * The raw HTML in a text: each piece that Kirby's Markdown parser,
     * Parsedown, passes on as it is, such as a tag, a comment, or a block
     * that starts with a tag. Parsedown itself decides, so the check cannot
     * differ from what the site renders. Plain Parsedown (config.php) and
     * ParsedownExtra (option markdown.extra) both look. An autolink to a web
     * or mail address, such as <https://example.com>, is a link and no raw
     * HTML. An autolink with another scheme, such as <javascript://...>,
     * counts, because a click on it runs in the site. Entities such as
     * &amp; do not count.
     *
     * @return list<string>
     */
    public static function html(string $text): array
    {
        $pieces = [];
        $collect = function (array $element) use (&$pieces): void {
            $raw = $element['rawHtml'] ?? null;
            if (is_string($raw) && preg_match('/^&#?\w+;$/', $raw) !== 1) {
                $pieces[] = $raw;
            }
        };
        $plain = new class ($collect) extends Parsedown {
            public function __construct(private Closure $collect) {}

            /** @param array<string, mixed> $Element */
            protected function element(array $Element): string
            {
                ($this->collect)($Element);
                return parent::element($Element);
            }
        };
        $extra = new class ($collect) extends ParsedownExtra {
            public function __construct(private Closure $collect)
            {
                parent::__construct();
            }

            /** @param array<string, mixed> $Element */
            protected function element(array $Element): string
            {
                ($this->collect)($Element);
                return parent::element($Element);
            }
        };
        $breaks = (bool) (kirby()->option('markdown')['breaks'] ?? true);
        $plain->setBreaksEnabled($breaks)->text($text);
        $extra->setBreaksEnabled($breaks)->text($text);
        preg_match_all('/<(?!https?:\/\/)\w+:\/\/[^\s>]*>?/i', $text, $links);
        return [...$pieces, ...$links[0]];
    }

    /** True when $after has raw HTML (html()) that $before does not have: a new piece, or a piece more often. */
    public static function addsHtml(string $before, string $after): bool
    {
        $old = array_count_values(self::html($before));
        foreach (array_count_values(self::html($after)) as $piece => $n) {
            if ($n > ($old[$piece] ?? 0)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Turns a propose tool call into a card. Returns the proposal, or the
     * error Claude gets back instead.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|string
     */
    public static function propose(ChatState $chat, string $name, array $input): array|string
    {
        // Kirby renders raw HTML in a text as it is, and the site allows
        // scripts. An instruction in a photo, a web page or an old post can
        // make Claude propose a <script>, and a card highlights links only.
        $html = false;
        array_walk_recursive($input, function ($value) use (&$html) {
            $html = $html || (is_string($value) && self::html($value) !== []);
        });
        if ($html) {
            return 'Raw HTML is not allowed. Use Markdown.';
        }
        return match ($name) {
            'propose_post' => self::post($chat, $input),
            'propose_edit' => self::edit($input),
            'propose_field' => self::field($input),
            'propose_file_fields' => self::fileFields($input),
            'propose_route' => self::route($chat, $input),
            'propose_slug' => self::slug($input),
            default => 'Unknown tool: ' . $name,
        };
    }

    /** Where "Übernehmen" writes, as the card says before the click. */
    private static function where(ModelWithContent $model): string
    {
        return Content::isDraft($model)
            ? 'Übernehmen schreibt direkt in den Entwurf.'
            : 'Übernehmen schreibt in die Änderungen. Live geht es erst mit deinem Speichern im Panel.';
    }

    /** "Foto „Aika“", "Über mich", "IMG_1.jpg in Foto „Aika“": a model as the cards name it. */
    private static function name(ModelWithContent $model): string
    {
        if ($model instanceof File) {
            return $model->filename() . ' in ' . self::name($model->page());
        }
        $template = $model instanceof Page ? $model->intendedTemplate()->name() : '';
        return isset(Types::ALL[$template])
            ? Types::ALL[$template]['label'] . ' „' . $model->title()->value() . '“'
            : $model->title()->value();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function card(string $kind, string $title, array $data): array
    {
        return ['id' => bin2hex(random_bytes(6)), 'kind' => $kind, 'state' => 'open', 'title' => $title, 'uploads' => [], ...$data];
    }

    /**
     * Upload ids that the open cards of the chat still use. An upload can be
     * in one open card only, because `Apply` deletes it after the click.
     *
     * @return list<string>
     */
    private static function needed(ChatState $chat): array
    {
        $decisions = Session::decisions();
        $ids = [];
        foreach ($chat->proposals as $id => $proposal) {
            if (in_array($decisions[$id]['state'] ?? 'open', ['open', 'failed'], true)) {
                $ids = [...$ids, ...($proposal['uploads'] ?? [])];
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * The field name in lowercase when $fields has it, else null. Claude may
     * write a field name in any case.
     *
     * @param array<string, mixed> $fields
     */
    private static function fieldName(array $fields, string $name): ?string
    {
        $name = strtolower(trim($name));
        return isset($fields[$name]) ? $name : null;
    }

    /** True for the field types the site renders as KirbyText. */
    private static function isMarkdown(string $type): bool
    {
        return in_array($type, ['markdown', 'textarea'], true);
    }

    /**
     * The links that a card highlights in a text of a field of $page.
     *
     * @return list<string>
     */
    private static function marks(Page $page, string $type, string $text): array
    {
        return self::isMarkdown($type) ? Marks::of($text, $page) : [];
    }

    /**
     * Checks a date: YYYY-MM-DD HH:MM:SS, or YYYY-MM-DD for a field without
     * time, in Europe/Berlin, not in the future. The site does not schedule
     * posts, so a date in the future is an error.
     */
    private static function dateError(string $value, bool $time = true): ?string
    {
        $format = $time ? 'Y-m-d H:i:s' : 'Y-m-d';
        $berlin = new DateTimeZone('Europe/Berlin');
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value, $berlin);
        if ($date === false || $date->format($format) !== $value) {
            return 'The date must be ' . ($time ? 'YYYY-MM-DD HH:MM:SS' : 'YYYY-MM-DD') . ', Europe/Berlin.';
        }
        return $date > new DateTimeImmutable('now', $berlin) ? 'The date lies in the future. Posts are not scheduled.' : null;
    }

    /** Checks a new slug: its form, and that no page uses it yet (`Content::slugTaken()`). */
    private static function slugError(string $slug): ?string
    {
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            return 'A slug takes a-z, 0-9 and single hyphens.';
        }
        $taken = Content::slugTaken($slug);
        return $taken === null ? null : "The slug $slug is taken by $taken. The URL must be unique.";
    }

    /** A value as a card shows it: toggles as ja or nein, files by their names. */
    private static function shown(Page $page, string $type, string $value): string
    {
        return match ($type) {
            'toggle' => $value === 'true' ? 'ja' : 'nein',
            'files' => implode(', ', array_map(fn($ref) => Content::fileRef($page, (string) $ref)?->filename() ?? (string) $ref, Content::list($value))),
            default => $value,
        };
    }

    /**
     * propose_field: a whole field of a post or of About.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|string
     */
    private static function field(array $input): array|string
    {
        $page = Content::find((string) $input['post']);
        if ($page === null) {
            return Tools::NO_POST;
        }
        $fields = array_diff_key(Content::fields($page), array_flip(Tools::ROUTE_FIELDS));
        $name = self::fieldName($fields, (string) $input['field']);
        if ($name === null) {
            return 'This field cannot be written: ' . $input['field'] . '. Fields: ' . implode(', ', array_keys($fields)) . '. Route, map points and zoom go through propose_route.';
        }
        $type = $fields[$name]['type'];
        $value = (string) $input['value'];
        $before = Content::current($page, $name);
        $stored = $value;
        switch ($type) {
            case 'date':
                $error = self::dateError($value, (bool) ($page->blueprint()->field($name)['time'] ?? false));
                break;
            case 'toggle':
                $error = in_array($value, ['true', 'false'], true) ? null : 'A toggle takes true or false.';
                break;
            case 'files':
                $file = $page->file($value);
                $error = $file !== null && $file->template() === 'image' ? null : "No image $value in this post. Images: " . implode(', ', Content::filenames($page)) . '.';
                $stored = $file !== null ? Yaml::encode([$file->uuid()->toString()]) : '';
                break;
            case 'text':
            case 'tags':
            case 'textarea':
            case 'markdown':
                $error = null;
                break;
            default:
                $error = "The field $name ($type) cannot be written by the assistant.";
        }
        if ($error !== null) {
            return $error;
        }
        return self::card('field', 'Feld · ' . self::name($page), [
            'where' => self::where($page),
            'unsaved' => Content::unsaved($page, $name),
            // marksBefore, marksAfter: the links that the card highlights in
            // the old and in the new value.
            'diff' => [[
                'label' => $fields[$name]['label'],
                'before' => self::shown($page, $type, $before),
                'after' => self::shown($page, $type, $stored),
                'marksBefore' => self::marks($page, $type, $before),
                'marksAfter' => self::marks($page, $type, $stored),
            ]],
            'input' => ['page' => $page->uuid()->toString(), 'field' => $name, 'value' => $stored, 'before' => $before],
        ]);
    }

    /**
     * propose_edit: one passage of a text field, in one or more versions.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|string
     */
    private static function edit(array $input): array|string
    {
        $page = Content::find((string) $input['post']);
        if ($page === null) {
            return Tools::NO_POST;
        }
        $fields = array_filter(Content::fields($page), fn($f) => in_array($f['type'], ['text', 'textarea', 'markdown'], true));
        $name = self::fieldName($fields, (string) $input['field']);
        if ($name === null) {
            return 'Passages exist in text fields only: ' . implode(', ', array_keys($fields)) . '.';
        }
        $passage = (string) $input['passage'];
        $current = Content::current($page, $name);
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
        foreach ($versions as $version) {
            $after = str_replace($passage, $version, $current);
            // A version without raw HTML can still make some together with
            // the text around it, such as "<b x" before a later ">".
            if (self::addsHtml($current, $after)) {
                return 'With this version the field would get raw HTML. Use Markdown.';
            }
            $marks[] = array_values(array_filter(self::marks($page, $type, $after), fn($mark) => str_contains($version, $mark)));
        }
        return self::card('edit', 'Passage · ' . self::name($page), [
            'where' => self::where($page),
            'unsaved' => Content::unsaved($page, $name),
            'field' => $fields[$name]['label'],
            'passage' => $passage,
            'versions' => $versions,
            // The links that the card highlights in the passage and in each version.
            'passageMarks' => array_values(array_filter(self::marks($page, $type, $current), fn($mark) => str_contains($passage, $mark))),
            'versionMarks' => $marks,
            'input' => ['page' => $page->uuid()->toString(), 'field' => $name, 'passage' => $passage, 'versions' => $versions],
        ]);
    }

    /**
     * propose_file_fields: alt text, caption or photographer of an image.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|string
     */
    private static function fileFields(array $input): array|string
    {
        $file = Content::file((string) $input['file']);
        if ($file === null || $file->template() !== 'image') {
            return 'No image found. read_post lists the file ids.';
        }
        $changes = [];
        $diff = [];
        foreach ($input['fields'] as $field) {
            $name = (string) $field['name'];
            $value = (string) $field['value'];
            if (!in_array($name, Tools::IMAGE_TEXTS, true) || isset($changes[$name])) {
                return "Each of alt, caption and photographer at most once; $name is not possible here.";
            }
            $before = Content::current($file, $name);
            $changes[$name] = ['before' => $before, 'after' => $value];
            $marks = fn(string $text) => $name === 'caption' ? Marks::of($text, null, true) : [];
            $diff[] = ['label' => (string) ($file->blueprint()->field($name)['label'] ?? $name), 'before' => $before, 'after' => $value, 'marksBefore' => $marks($before), 'marksAfter' => $marks($value)];
        }
        if ($changes === []) {
            return 'Give at least one field.';
        }
        return self::card('file', 'Bildtexte · ' . self::name($file), [
            'where' => self::where($file),
            'diff' => $diff,
            'input' => ['file' => $file->uuid()->toString(), 'changes' => $changes],
        ]);
    }

    /**
     * A point of a route input as [lon, lat], or null when its coordinates
     * are out of range.
     *
     * @param array<string, mixed> $point
     * @return ?array{float, float}
     */
    private static function point(array $point): ?array
    {
        $lat = (float) $point['lat'];
        $lon = (float) $point['lon'];
        return abs($lat) <= 90 && abs($lon) <= 180 ? [$lon, $lat] : null;
    }

    /**
     * Builds the route of a card, plus markers and zoom. The line comes from
     * stations, routed by car through OSRM (a public routing service), or
     * from a GPX upload of this chat. Returns the route, or the error message
     * for Claude.
     *
     * @param array<string, mixed> $route
     * @return array<string, mixed>|string
     */
    private static function buildRoute(array $route): array|string
    {
        $stations = $route['stations'];
        $gpx = trim((string) $route['gpx']);
        if (($stations === []) === ($gpx === '')) {
            return 'A route takes either stations or a GPX upload id.';
        }
        $markers = [];
        foreach ([...array_filter($stations, fn($s) => $s['marker']), ...$route['markers']] as $marker) {
            $point = self::point($marker);
            if ($point === null) {
                return 'A marker lies outside the map: lat -90 to 90, lon -180 to 180.';
            }
            $markers[] = ['label' => (string) $marker['label'], 'lon' => $point[0], 'lat' => $point[1]];
        }
        if ($gpx !== '') {
            $meta = Uploads::meta($gpx);
            if ($meta === null || $meta['kind'] !== 'gpx') {
                return "No GPX upload $gpx in this chat.";
            }
            if (isset($meta['error'])) {
                return $meta['error'];
            }
            // Rides start and end at home, so no marker may lie near the
            // uncut ends. They are stored rounded to 3 decimals, and
            // `Gpx::ZONE_SLACK` adds the distance that rounding can move them.
            foreach ($markers as $marker) {
                foreach ($meta['ends'] as $end) {
                    if (Route::distance([$marker['lon'], $marker['lat']], $end) < Gpx::ZONE + Gpx::ZONE_SLACK) {
                        return 'The marker "' . $marker['label'] . '" lies closer than ' . Gpx::ZONE . ' m to an end of the ride. Markers there are not possible.';
                    }
                }
            }
            $line = ['line' => $meta['line'], 'km' => $meta['km'], 'seconds' => $meta['seconds'], 'source' => 'GPX', 'upload' => $gpx];
        } else {
            $points = array_map(self::point(...), $stations);
            if (count($points) < 2 || in_array(null, $points, true)) {
                return 'A route takes at least two stations on the map: lat -90 to 90, lon -180 to 180.';
            }
            try {
                $line = Route::osrm($points, option('blog.assistant.http')) + ['source' => 'OSRM', 'upload' => null];
            } catch (RuntimeException $e) {
                return $e->getMessage();
            }
        }
        return $line + ['points' => count($line['line']), 'markers' => $markers, 'zoom' => (bool) $route['zoom']];
    }

    /**
     * A route as the card shows it: km, driving time, points, markers, zoom.
     *
     * @param ?array<string, mixed> $route
     * @return array<string, string>
     */
    private static function routeRows(?array $route): array
    {
        if ($route === null) {
            return ['Länge' => '', 'Fahrzeit' => '', 'Punkte' => '', 'Marker' => '', 'Karte zoombar' => ''];
        }
        return [
            'Länge' => number_format($route['km'], 1, ',', '.') . ' km' . (isset($route['source']) ? ' · ' . $route['source'] : ''),
            'Fahrzeit' => $route['seconds'] === null ? '' : Route::duration($route['seconds']),
            'Punkte' => (string) $route['points'],
            'Marker' => implode(', ', array_column($route['markers'], 'label')),
            'Karte zoombar' => $route['zoom'] ? 'ja' : 'nein',
        ];
    }

    /**
     * propose_route: a new route for a photo or trip, replacing route, points and zoom.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|string
     */
    private static function route(ChatState $chat, array $input): array|string
    {
        $page = Content::find((string) $input['post']);
        if ($page === null || !Types::hasMap($page->intendedTemplate()->name())) {
            return 'A route is possible for photo and trip posts only. find_posts lists the ids.';
        }
        $route = self::buildRoute($input['route']);
        if (is_string($route)) {
            return $route;
        }
        if ($route['upload'] !== null && in_array($route['upload'], self::needed($chat), true)) {
            return Tools::GPX_TAKEN;
        }
        $before = Content::routeSummary($page);
        $old = self::routeRows($before['points'] > 0 || $before['markers'] !== [] ? $before : null);
        $new = self::routeRows($route);
        return self::card('route', 'Route · ' . self::name($page), [
            'where' => self::where($page),
            'uploads' => array_filter([$route['upload']]),
            'diff' => array_map(fn($label) => ['label' => $label, 'before' => $old[$label], 'after' => $new[$label]], array_keys($new)),
            'input' => ['page' => $page->uuid()->toString(), 'route' => $route, 'hash' => $before['hash']],
        ]);
    }

    /**
     * propose_slug: a new slug for a post. About keeps its slug, because its
     * blueprint does not allow a change (`changeSlug: false`).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|string
     */
    private static function slug(array $input): array|string
    {
        $page = Content::find((string) $input['post']);
        if (!$page instanceof PostPage) {
            return 'Only a post gets a new slug; about keeps its slug. find_posts lists the ids.';
        }
        $slug = (string) $input['slug'];
        if ($slug === $page->slug()) {
            return "The post has the slug $slug already.";
        }
        if (($error = self::slugError($slug)) !== null) {
            return $error;
        }
        $redirect = (bool) $input['redirect'];
        return self::card('slug', 'URL · ' . self::name($page), [
            'where' => match (true) {
                $page->isDraft() => 'Übernehmen ändert die URL des Entwurfs.',
                $redirect => 'Übernehmen ändert die URL sofort, auch live. Die alte URL leitet weiter.',
                default => 'Übernehmen ändert die URL sofort, auch live. Die alte URL leitet nicht weiter.',
            },
            'diff' => [['label' => 'URL', 'before' => $page->postPath(), 'after' => $page->postPath($slug), 'marksBefore' => [], 'marksAfter' => []]],
            'input' => ['page' => $page->uuid()->toString(), 'slug' => $slug, 'before' => $page->slug(), 'draft' => $page->isDraft(), 'redirect' => $redirect],
        ]);
    }

    /**
     * The new draft as a page that exists only in memory, with its photos as
     * files. Its texts then render as they will in the real draft, and
     * (image:) finds the photos.
     *
     * @param list<array<string, mixed>> $photos
     */
    private static function draft(Page $section, string $type, string $slug, array $photos): Page
    {
        return Page::factory([
            'slug' => $slug,
            'template' => $type,
            'parent' => $section,
            'isDraft' => true,
            'files' => array_map(fn($p) => ['filename' => $p['filename'], 'template' => 'image', 'content' => ['alt' => $p['alt'], 'caption' => $p['caption']]], $photos),
        ]);
    }

    /**
     * propose_post: a new draft with fields, photos and route in one click.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|string
     */
    private static function post(ChatState $chat, array $input): array|string
    {
        $type = (string) $input['type'];
        if (!isset(Types::ALL[$type]) || site()->find(Types::ALL[$type]['section']) === null) {
            return 'No section for the type ' . $type . '.';
        }
        $slug = (string) $input['slug'];
        if (($error = self::slugError($slug)) !== null) {
            return $error;
        }
        $date = (string) $input['date'];
        if (($error = self::dateError($date)) !== null) {
            return $error;
        }
        $title = trim((string) $input['title']);
        if ($title === '') {
            return 'title: A post needs a title.';
        }
        $trip = trim((string) $input['trip']);
        if ($trip !== '' && $type !== 'trip') {
            return 'trip: only trip posts have this field.';
        }
        $needed = self::needed($chat);
        $photos = [];
        foreach ($input['photos'] as $photo) {
            $id = (string) $photo['upload'];
            $filename = (string) $photo['filename'];
            $meta = Uploads::meta($id);
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
            $photos[] = ['upload' => $id, 'filename' => $filename, 'alt' => (string) $photo['alt'], 'caption' => (string) $photo['caption'], 'photographer' => (string) $photo['photographer'], 'exif' => $meta['exif'], 'marks' => Marks::of((string) $photo['caption'], null, true)];
        }
        $names = array_column($photos, 'filename');
        $cover = (string) $input['cover'];
        if ($cover !== '' && !in_array($cover, $names, true)) {
            return 'cover is one of the file names: ' . implode(', ', $names) . '.';
        }
        $map = Types::hasMap($type);
        $draft = self::draft(site()->find(Types::ALL[$type]['section']), $type, $slug, $photos);
        $route = null;
        if ($input['route']['stations'] !== [] || trim((string) $input['route']['gpx']) !== '') {
            if (!$map) {
                return 'A route is possible for photo and trip posts only.';
            }
            $route = self::buildRoute($input['route']);
            if (is_string($route)) {
                return "route: $route";
            }
            if ($route['upload'] !== null && in_array($route['upload'], $needed, true)) {
                return Tools::GPX_TAKEN;
            }
        }
        $content = ['title' => $title, 'date' => $date, 'intro' => (string) $input['intro'], 'text' => (string) $input['text'], 'pick' => $input['pick'] ? 'true' : 'false'] + ($type === 'trip' ? ['trip' => $trip] : []);
        $rows = [
            ['label' => 'Titel', 'value' => $title],
            ['label' => 'URL-Anhang', 'value' => $slug],
            ['label' => 'Datum', 'value' => $date],
            ['label' => 'Anriss', 'value' => $content['intro'], 'marks' => Marks::of($content['intro'], $draft)],
            ['label' => 'Text', 'value' => $content['text'], 'marks' => Marks::of($content['text'], $draft)],
            ['label' => 'Titelbild', 'value' => $cover],
            ['label' => 'Hervorgehoben', 'value' => $input['pick'] ? 'ja' : 'nein'],
            ...($type === 'trip' ? [['label' => 'Reise', 'value' => $trip]] : []),
        ];
        foreach ($route === null ? [] : self::routeRows($route) as $label => $value) {
            $rows[] = ['label' => $label, 'value' => $value];
        }
        return self::card('post', 'Neuer Entwurf · ' . Types::ALL[$type]['label'], [
            'where' => 'Anlegen legt einen Entwurf an. Veröffentlichen bleibt bei dir.',
            'uploads' => [...array_column($photos, 'upload'), ...array_filter([$route['upload'] ?? null])],
            'rows' => $rows,
            'photos' => array_map(fn($p) => array_diff_key($p, ['exif' => true]), $photos),
            'input' => ['type' => $type, 'slug' => $slug, 'content' => $content, 'photos' => array_map(fn($p) => array_diff_key($p, ['marks' => true]), $photos), 'cover' => $cover, 'route' => $route],
        ]);
    }
}
