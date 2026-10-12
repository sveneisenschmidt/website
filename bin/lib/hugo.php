<?php

/**
 * Hugo to Kirby import (phase 2 of the migration). Everything the import
 * does not know stops it with an exception that names the file: nothing
 * is dropped without a word.
 */

const HUGO_SITE_TITLE = 'Sven Eisenschmidt - Personal Blog';
const HUGO_EMAIL = 'sven.eisenschmidt@gmail.com';
const HUGO_SECTIONS = ['photos' => 'photo', 'trips' => 'trip', 'notes' => 'note', 'essays' => 'essay'];
const HUGO_POST_KEYS = ['title', 'date', 'type', 'cover', 'pick', 'draft', 'trip'];
const HUGO_ABOUT_KEYS = ['title', 'date', 'showDate', 'draft', 'label', 'lastmod', 'build.list'];
const HUGO_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
// Folders and the files without a Hugo source: 2026-10-12 12:00:00 UTC.
const HUGO_FIXED_TIME = 1791806400;
// A shortcode argument: name="value", with \" inside the value.
const HUGO_ATTR = '/(\w+)="((?:[^"\\\\]|\\\\.)*)"/';

/** Splits "+++ toml +++ body" into [fields, body]. Knows the TOML this site uses. */
function hugo_front_matter(string $md): array
{
    if (!preg_match('/\A\+\+\+\n(.*?)\n\+\+\+\n?(.*)\z/s', $md, $m)) {
        throw new RuntimeException('No TOML front matter');
    }
    $fields = [];
    $table = '';
    foreach (explode("\n", $m[1]) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (preg_match('/^\[([a-z.]+)\]$/i', $line, $t)) {
            $table = $t[1] . '.';
            continue;
        }
        if (!preg_match('/^([a-z][a-z0-9_.]*)\s*=\s*(.+)$/i', $line, $kv)) {
            throw new RuntimeException("Unknown front matter line: $line");
        }
        $fields[$table . $kv[1]] = hugo_toml_value($kv[2]);
    }
    return [$fields, $m[2]];
}

/** A TOML scalar: 'single', "double", true or false. */
function hugo_toml_value(string $raw): string|bool
{
    $raw = trim($raw);
    if ($raw === 'true' || $raw === 'false') {
        return $raw === 'true';
    }
    // An unquoted TOML date, as in date = 2026-06-13T09:00:00.
    if (preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})?)?$/', $raw)) {
        return $raw;
    }
    if (preg_match("/^'([^']*)'$/", $raw, $m)) {
        return $m[1];
    }
    if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/', $raw, $m)) {
        return stripcslashes($m[1]);
    }
    throw new RuntimeException("Unsupported TOML value: $raw");
}

/** Hugo date (local time or with offset) as Kirby date in Berlin time. */
function hugo_date(string $value): string
{
    $berlin = new DateTimeZone('Europe/Berlin');
    return (new DateTimeImmutable($value, $berlin))->setTimezone($berlin)->format('Y-m-d H:i:s');
}

/** Unix time of a Kirby date in Berlin time. */
function hugo_time(string $date): int
{
    return (new DateTimeImmutable($date, new DateTimeZone('Europe/Berlin')))->getTimestamp();
}

/** [intro, text]: the text before <!--more--> and the rest. Without the marker the intro is empty. */
function hugo_split_intro(string $body): array
{
    $parts = explode('<!--more-->', $body, 2);
    return count($parts) === 2 ? [trim($parts[0]), trim($parts[1])] : ['', trim($body)];
}

/** Arguments of a shortcode. Anything that is not name="value" stops the import. */
function hugo_attrs(string $raw, array $known): array
{
    preg_match_all(HUGO_ATTR, $raw, $m, PREG_SET_ORDER);
    $attrs = [];
    // Hugo unescapes only \" in shortcode arguments.
    foreach ($m as [, $key, $value]) {
        $attrs[$key] = str_replace('\"', '"', $value);
    }
    $rest = trim(preg_replace(HUGO_ATTR, '', $raw));
    if ($rest !== '') {
        throw new RuntimeException("Unparsed shortcode arguments: $rest");
    }
    if ($unknown = array_diff(array_keys($attrs), $known)) {
        throw new RuntimeException('Unknown shortcode arguments: ' . implode(', ', $unknown));
    }
    return $attrs;
}

/**
 * Converts the Hugo shortcodes of a body to KirbyText. Returns
 * [text, images, map]: images maps a file name to its alt, caption and
 * photographer; map is null or [route, zoom, points]. $pageUuid gives the
 * UUID of a post for {{< ref "slug" >}}.
 */
function hugo_convert(string $body, callable $pageUuid): array
{
    $images = [];
    $map = null;
    $image = function (string $name, array $texts) use (&$images): string {
        if (isset($images[$name])) {
            throw new RuntimeException("Image used twice: $name");
        }
        $images[$name] = $texts + ['alt' => '', 'caption' => '', 'photographer' => ''];
        return "(image: $name)";
    };

    // KirbyText keeps HTML as it is. A blank line would end the HTML block in
    // Markdown and the rest would show as code, so blank lines go.
    $body = preg_replace_callback(
        '/\{\{<\s*rawhtml\s*>\}\}\n?(.*?)\n?\{\{<\s*\/rawhtml\s*>\}\}/s',
        fn ($m) => preg_replace('/\n[ \t]*(?=\n)/', '', $m[1]),
        $body
    );

    $body = preg_replace_callback('/\{\{<\s*map\b(.*?)>\}\}(.*?)\{\{<\s*\/map\s*>\}\}/s', function ($m) use (&$map) {
        if ($map !== null) {
            throw new RuntimeException('More than one map in a post');
        }
        $attrs = hugo_attrs($m[1], ['route', 'zoom']);
        $points = [];
        foreach (preg_split('/\R/', trim($m[2])) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // As the Hugo shortcode: latitude, longitude, the rest is the label.
            $parts = explode(',', $line, 3);
            if (count($parts) < 2 || !is_numeric(trim($parts[0])) || !is_numeric(trim($parts[1]))) {
                throw new RuntimeException("Invalid map line: $line");
            }
            $points[] = ['lat' => trim($parts[0]), 'lon' => trim($parts[1]), 'label' => trim($parts[2] ?? '')];
        }
        $map = ['route' => $attrs['route'] ?? '', 'zoom' => ($attrs['zoom'] ?? '') === 'true', 'points' => $points];
        return '(map:)';
    }, $body);

    $body = preg_replace_callback('/\{\{<\s*img\s+(.*?)\s*\/?>\}\}/s', function ($m) use ($image) {
        $attrs = hugo_attrs($m[1], ['src', 'alt', 'caption', 'photographer']);
        if (!isset($attrs['src'])) {
            throw new RuntimeException("img without src: {$m[0]}");
        }
        $src = $attrs['src'];
        unset($attrs['src']);
        return $image($src, $attrs);
    }, $body);

    // Markdown images of files in the bundle: ![alt](file.jpg).
    $body = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s\/:]+)\)/', fn ($m) => $image($m[2], ['alt' => $m[1]]), $body);

    // Goldmark attribute under an image: {class="inline"} goes onto the <img>.
    $body = preg_replace('/\(image: ([^)\s]+)\)\n\{class="([\w -]+)"\}/', '(image: $1 imgclass: $2)', $body);
    if (preg_match('/^\{(?:[.#][\w-]+|[\w-]+=)[^}\n]*\}$/m', $body, $attr)) {
        throw new RuntimeException("Unknown Markdown attribute: {$attr[0]}");
    }

    // Kirby's Markdown does not read _[text](url_with_underscores)_ as emphasis.
    $body = preg_replace('/(?<![\w*])_(\[[^\]\n]+\]\([^)\s]+\))_(?![\w*])/', '*$1*', $body);

    $body = preg_replace_callback(
        '/\[([^\]]+)\]\(\{\{<\s*ref\s+"([^"]+)"\s*>\}\}\)/',
        fn ($m) => '(link: page://' . $pageUuid($m[2]) . ' text: ' . $m[1] . ')',
        $body
    );

    // As the Hugo shortcode: a plain mailto link with the text "Email".
    $body = preg_replace_callback('/\{\{<\s*email\b(.*?)>\}\}/s', function ($m) {
        $attrs = hugo_attrs($m[1], ['subject']);
        $query = isset($attrs['subject']) ? '?subject=' . rawurlencode($attrs['subject']) : '';
        return '[Email](mailto:' . HUGO_EMAIL . $query . ')';
    }, $body);

    if (preg_match('/\{\{[<%].*?[>%]\}\}/s', $body, $left)) {
        throw new RuntimeException("Unknown shortcode: {$left[0]}");
    }
    return [$body, $images, $map];
}

/** Stable 16-char id, used as Kirby UUID. */
function hugo_uid(string $s): string
{
    return substr(hash('sha256', 'website:' . $s), 0, 16);
}

/** Writes a Kirby content file with a fixed time. */
function hugo_write(string $path, array $fields, int $mtime): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException("Cannot create $dir");
    }
    if (file_put_contents($path, Kirby\Data\Txt::encode($fields) . "\n") === false) {
        throw new RuntimeException("Cannot write $path");
    }
    touch($path, $mtime);
}

/** Copies a file with the given time. On macOS as APFS clone: no extra space. */
function hugo_copy(string $src, string $dst, int $mtime): void
{
    $done = false;
    if (PHP_OS_FAMILY === 'Darwin') {
        exec('cp -c ' . escapeshellarg($src) . ' ' . escapeshellarg($dst) . ' 2>/dev/null', $out, $code);
        $done = $code === 0;
    }
    if (!$done && !copy($src, $dst)) {
        throw new RuntimeException("Cannot copy $src");
    }
    touch($dst, $mtime);
}

/** Files of a bundle folder, without index.md and dot files. A folder inside stops the import. */
function hugo_bundle_files(?string $dir): array
{
    if ($dir === null) {
        return [];
    }
    $files = [];
    foreach (scandir($dir) as $name) {
        if ($name[0] === '.' || $name === 'index.md') {
            continue;
        }
        if (is_dir("$dir/$name")) {
            throw new RuntimeException("Unexpected folder in bundle: $dir/$name");
        }
        $files[] = $name;
    }
    return $files;
}

/** Copies one file and writes its meta file (template, texts, EXIF, UUID), both with the given time. */
function hugo_import_file(string $src, string $dst, string $uuid, array $texts, callable $exif, int $mtime): void
{
    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
    $image = in_array($ext, HUGO_IMAGE_EXTENSIONS, true);
    if (!$image && $ext !== 'json') {
        throw new RuntimeException("Unknown file type: $src");
    }
    hugo_copy($src, $dst, $mtime);
    $meta = ['template' => $image ? 'image' : 'route'];
    if ($image) {
        $meta += array_filter($texts, fn ($v) => $v !== '') + $exif($src);
    }
    $meta['uuid'] = $uuid;
    hugo_write("$dst.txt", $meta, $mtime);
}

/** Every post of the four sections, by slug. */
function hugo_posts(string $hugo): array
{
    $posts = [];
    foreach (HUGO_SECTIONS as $section => $type) {
        foreach (scandir("$hugo/$section") as $entry) {
            if ($entry[0] === '.' || $entry[0] === '_') {
                continue;
            }
            $path = "$hugo/$section/$entry";
            $bundle = is_dir($path);
            if (!$bundle && !str_ends_with($entry, '.md')) {
                throw new RuntimeException("Unknown entry: $path");
            }
            $slug = $bundle ? $entry : substr($entry, 0, -3);
            if (isset($posts[$slug])) {
                throw new RuntimeException("Slug used twice: $slug");
            }
            $md = $bundle ? "$path/index.md" : $path;
            try {
                [$fm, $body] = hugo_front_matter(file_get_contents($md));
            } catch (RuntimeException $e) {
                throw new RuntimeException("$md: " . $e->getMessage(), 0, $e);
            }
            $posts[$slug] = [
                'section' => $section,
                'type' => $type,
                'slug' => $slug,
                'md' => $md,
                'dir' => $bundle ? $path : null,
                'fm' => $fm,
                'body' => $body,
                'draft' => ($fm['draft'] ?? false) === true,
            ];
        }
    }
    return $posts;
}

/** Writes one post and its files. Returns the number of files. */
function hugo_import_post(array $post, string $content, callable $pageUuid, callable $exif): int
{
    $fm = $post['fm'];
    if ($unknown = array_diff(array_keys($fm), HUGO_POST_KEYS)) {
        throw new RuntimeException('Unknown front matter: ' . implode(', ', $unknown));
    }
    foreach (['title', 'date', 'type'] as $key) {
        if (!is_string($fm[$key] ?? null) || $fm[$key] === '') {
            throw new RuntimeException("Missing front matter: $key");
        }
    }
    if ($fm['type'] !== $post['type']) {
        throw new RuntimeException("type is not {$post['type']}");
    }
    [$body, $images, $map] = hugo_convert($post['body'], $pageUuid);
    [$intro, $text] = hugo_split_intro($body);

    $files = hugo_bundle_files($post['dir']);
    $fileUuid = fn (string $name) => hugo_uid('file:' . $post['slug'] . '/' . $name);
    $needed = array_merge(array_keys($images), array_filter([$fm['cover'] ?? null, $map['route'] ?? null]));
    if ($missing = array_diff($needed, $files)) {
        throw new RuntimeException('Missing files: ' . implode(', ', $missing));
    }

    $date = hugo_date($fm['date']);
    // Content and files take the time of the post: the same on every clone, as Hugo's sitemap.
    $time = hugo_time($date);
    $fields = ['title' => $fm['title'], 'date' => $date, 'intro' => $intro, 'text' => $text];
    if (isset($fm['cover'])) {
        $fields['cover'] = ['file://' . $fileUuid($fm['cover'])];
    }
    if (($fm['pick'] ?? false) === true) {
        $fields['pick'] = 'true';
    }
    if (isset($fm['trip'])) {
        $fields['trip'] = $fm['trip'];
    }
    if ($map !== null) {
        $fields['route'] = ['file://' . $fileUuid($map['route'])];
        $fields['mappoints'] = $map['points'];
        if ($map['zoom']) {
            $fields['mapzoom'] = 'true';
        }
    }
    $fields['uuid'] = hugo_uid('page:' . $post['slug']);

    $folder = $content . '/' . $post['section'] . '/'
        . ($post['draft'] ? '_drafts/' . $post['slug'] : str_replace('-', '', substr($date, 0, 10)) . '_' . $post['slug']);
    hugo_write("$folder/{$post['type']}.txt", $fields, $time);
    foreach ($files as $name) {
        hugo_import_file($post['dir'] . '/' . $name, "$folder/$name", $fileUuid($name), $images[$name] ?? [], $exif, $time);
    }
    return count($files);
}

/** Writes the about page and its files. Returns the number of files. */
function hugo_import_about(string $hugo, string $content, callable $pageUuid, callable $exif): int
{
    $md = "$hugo/about/index.md";
    [$fm, $body] = hugo_front_matter(file_get_contents($md));
    if ($unknown = array_diff(array_keys($fm), HUGO_ABOUT_KEYS)) {
        throw new RuntimeException("$md: unknown front matter " . implode(', ', $unknown));
    }
    if (($fm['draft'] ?? false) === true) {
        throw new RuntimeException("$md: about is a draft");
    }
    [$text, $images, $map] = hugo_convert($body, $pageUuid);
    if ($map !== null) {
        throw new RuntimeException("$md: map on the about page");
    }
    $time = isset($fm['lastmod']) ? hugo_time(hugo_date($fm['lastmod'])) : HUGO_FIXED_TIME;
    hugo_write("$content/about/about.txt", [
        'title' => $fm['title'],
        'label' => $fm['label'] ?? '',
        'lastmod' => $fm['lastmod'] ?? '',
        'text' => trim($text),
        'uuid' => hugo_uid('about'),
    ], $time);
    $files = hugo_bundle_files("$hugo/about");
    foreach ($files as $name) {
        hugo_import_file("$hugo/about/$name", "$content/about/$name", hugo_uid("file:about/$name"), $images[$name] ?? [], $exif, $time);
    }
    return count($files);
}

/** Writes the archive page and its children, one per stream. */
function hugo_import_archive(string $hugo, string $content): void
{
    foreach (scandir("$hugo/archive") as $entry) {
        if (!str_ends_with($entry, '.md')) {
            continue;
        }
        $md = "$hugo/archive/$entry";
        [$fm] = hugo_front_matter(file_get_contents($md));
        if ($unknown = array_diff(array_keys($fm), ['title', 'layout', 'params.stream', 'build.list'])) {
            throw new RuntimeException("$md: unknown front matter " . implode(', ', $unknown));
        }
        $child = $entry === '_index.md' ? '' : '/' . substr($entry, 0, -3);
        hugo_write("$content/archive$child/archive.txt", [
            'title' => $fm['title'],
            'stream' => $fm['params.stream'],
            'uuid' => hugo_uid("archive$child"),
        ], HUGO_FIXED_TIME);
    }
}

/**
 * Imports the Hugo content folder $hugo into $content, which must not
 * exist yet. $exif reads the EXIF of an image. Returns the counts.
 */
function hugo_import(string $hugo, string $content, callable $exif): array
{
    if (file_exists($content)) {
        throw new RuntimeException("$content exists");
    }
    $posts = hugo_posts($hugo);
    $pageUuid = fn (string $slug) => isset($posts[$slug])
        ? hugo_uid("page:$slug")
        : throw new RuntimeException("ref to unknown post: $slug");

    hugo_write("$content/site.txt", ['title' => HUGO_SITE_TITLE, 'uuid' => hugo_uid('site')], HUGO_FIXED_TIME);
    hugo_write("$content/home/home.txt", ['title' => 'Home', 'uuid' => hugo_uid('home')], HUGO_FIXED_TIME);
    hugo_write("$content/error/error.txt", ['title' => 'Page not found', 'uuid' => hugo_uid('error')], HUGO_FIXED_TIME);
    foreach (array_keys(HUGO_SECTIONS) as $section) {
        $md = "$hugo/$section/_index.md";
        [$fm] = hugo_front_matter(file_get_contents($md));
        if ($unknown = array_diff(array_keys($fm), ['title', 'build.list', 'build.render'])) {
            throw new RuntimeException("$md: unknown front matter " . implode(', ', $unknown));
        }
        hugo_write("$content/$section/$section.txt", ['title' => $fm['title'], 'uuid' => hugo_uid("section:$section")], HUGO_FIXED_TIME);
    }

    $counts = ['photo' => 0, 'trip' => 0, 'note' => 0, 'essay' => 0, 'drafts' => 0, 'files' => 0];
    foreach ($posts as $post) {
        try {
            $counts['files'] += hugo_import_post($post, $content, $pageUuid, $exif);
        } catch (Throwable $e) {
            throw new RuntimeException($post['md'] . ': ' . $e->getMessage(), 0, $e);
        }
        $counts[$post['type']]++;
        $counts['drafts'] += $post['draft'] ? 1 : 0;
    }
    $counts['files'] += hugo_import_about($hugo, $content, $pageUuid, $exif);
    hugo_import_archive($hugo, $content);

    $md = "$hugo/search/_index.md";
    [$fm] = hugo_front_matter(file_get_contents($md));
    if ($unknown = array_diff(array_keys($fm), ['title', 'layout', 'build.list'])) {
        throw new RuntimeException("$md: unknown front matter " . implode(', ', $unknown));
    }
    hugo_write("$content/search/search.txt", ['title' => $fm['title'], 'uuid' => hugo_uid('search')], HUGO_FIXED_TIME);

    // One fixed time for all folders: Kirby's Site::modified() reads them too.
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($content, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($items as $item) {
        if ($item->isDir()) {
            touch($item->getPathname(), HUGO_FIXED_TIME);
        }
    }
    touch($content, HUGO_FIXED_TIME);
    return $counts;
}
