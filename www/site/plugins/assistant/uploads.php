<?php

use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;

/**
 * Uploads of the chat (spec, "Uploads"): photos and GPX files Sven drags
 * into the chat. They live in storage/assistant/ next to www/ and vendor/,
 * outside the document root, one folder per chat. Its random name stands
 * only in the session. The server names every file itself. A JPEG loses its
 * metadata on arrival, PNG and WebP too; a GPX file is read and deleted at
 * once, only the cut line stays. Previews are files in the folder; the
 * session and the chat hold only their names.
 */

const BLOG_UPLOADS_SESSION = 'blog.assistant.uploads';

/** Accepted image types: MIME type from finfo => extension. */
const BLOG_UPLOAD_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

const BLOG_UPLOAD_MAX_BYTES = 60 * 1024 * 1024;
const BLOG_UPLOAD_MAX_PIXELS = 64000000;

/** Long edge of a preview for Claude and of a thumbnail on a card, in pixels. */
const BLOG_UPLOAD_PREVIEW = 1568;
const BLOG_UPLOAD_THUMB = 240;

/** Everything goes at the latest after this many days. */
const BLOG_UPLOAD_DAYS = 7;

/** An upload id, and the image files a message may name. */
const BLOG_UPLOAD_ID = '/^u[0-9a-f]{16}$/';
const BLOG_UPLOAD_IMAGE = '/^[uv][0-9a-f]{16}(?:\.preview)?\.jpg$/';
const BLOG_UPLOAD_FOLDER = '/^[0-9a-f]{32}$/';

/** Code in an image file: such a file is refused. */
const BLOG_UPLOAD_CODE = '/<\?php|<script|<html/i';

function blog_uploads_root(): string
{
    $root = (string)(option('blog.assistant.storage') ?? dirname(kirby()->root('index')) . '/storage/assistant');
    Dir::make($root);
    if (!is_file($root . '/.htaccess')) {
        F::write($root . '/.htaccess', "# Uploads of the assistant: never served.\nRequire all denied\n");
    }
    return $root;
}

/** The uploads of this session's chat: folder, upload ids with their kind, ids not yet sent. */
function blog_uploads_state(): array
{
    return kirby()->session()->get(BLOG_UPLOADS_SESSION) ?? ['folder' => null, 'items' => [], 'pending' => []];
}

function blog_uploads_save(array $state): void
{
    kirby()->session()->set(BLOG_UPLOADS_SESSION, $state);
}

/** The folder of this chat, created on first use. Every call counts as use. */
function blog_uploads_dir(): string
{
    $state = blog_uploads_state();
    if ($state['folder'] === null) {
        $state['folder'] = bin2hex(random_bytes(16));
        blog_uploads_save($state);
    }
    $dir = blog_uploads_root() . '/' . $state['folder'];
    Dir::make($dir);
    touch($dir . '/.last');
    return $dir;
}

function blog_uploads_error(string $text): BlogAssistantError
{
    return new BlogAssistantError('upload', $text . ' Nichts geändert.');
}

/**
 * Takes one uploaded file: checks it, stores it under a name of the server
 * and marks it for the next message. Returns the stored metadata.
 * Throws BlogAssistantError with the reason the Panel shows.
 */
function blog_uploads_accept(string $tmp, string $name): array
{
    $size = (int)@filesize($tmp);
    if ($size === 0) {
        throw blog_uploads_error('Die Datei ist leer.');
    }
    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'gpx') {
        return blog_uploads_accept_gpx($tmp, $name);
    }
    if ($size > BLOG_UPLOAD_MAX_BYTES) {
        throw blog_uploads_error('Die Datei ist größer als 60 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $ext = BLOG_UPLOAD_TYPES[$mime] ?? null;
    $info = @getimagesize($tmp);
    if ($ext === null || $info === false || image_type_to_mime_type($info[2]) !== $mime) {
        throw blog_uploads_error('Nur JPEG, PNG, WebP und GPX.');
    }
    [$width, $height] = $info;
    if ($width < 1 || $height < 1 || $width * $height > BLOG_UPLOAD_MAX_PIXELS) {
        throw blog_uploads_error('Das Bild hat mehr als 64 Megapixel.');
    }
    $bytes = (string)file_get_contents($tmp);
    if (preg_match(BLOG_UPLOAD_CODE, $bytes)) {
        throw blog_uploads_error('Die Datei enthält Code. Exportier das Bild neu.');
    }
    $exif = [];
    $orientation = 1;
    try {
        if ($ext === 'jpg') {
            // Camera data becomes file fields; GPS and the rest go with the segments.
            $exif = blog_read_exif($tmp);
            $orientation = (int)((@exif_read_data($tmp) ?: [])['Orientation'] ?? 1);
            $bytes = blog_jpeg_strip($bytes, $orientation);
        } else {
            $bytes = $ext === 'png' ? blog_png_strip($bytes) : blog_webp_strip($bytes);
        }
    } catch (RuntimeException) {
        throw blog_uploads_error('Die Datei ist beschädigt.');
    }
    $id = 'u' . bin2hex(random_bytes(8));
    $dir = blog_uploads_dir();
    F::write("$dir/$id.$ext", $bytes);
    $turned = $orientation >= 5 && $orientation <= 8;
    $meta = [
        'kind' => 'image',
        'ext' => $ext,
        'name' => blog_uploads_name($name, $ext),
        'width' => $turned ? $height : $width,
        'height' => $turned ? $width : $height,
        'orientation' => $orientation,
        'exif' => $exif,
        'time' => time(),
    ];
    try {
        blog_uploads_image("$dir/$id.$ext", $orientation, null, BLOG_UPLOAD_PREVIEW, "$id.preview.jpg");
        blog_uploads_image("$dir/$id.$ext", $orientation, null, BLOG_UPLOAD_THUMB, "$id.thumb.jpg");
    } catch (RuntimeException) {
        array_map(fn ($file) => F::remove($file), glob("$dir/$id.*") ?: []);
        throw blog_uploads_error('Das Bild lässt sich nicht lesen.');
    }
    return blog_uploads_register($id, $meta);
}

/** A GPX file: read, deleted at once; only the cut line and its numbers stay. */
function blog_uploads_accept_gpx(string $tmp, string $name): array
{
    try {
        $gpx = blog_gpx_read($tmp);
    } catch (RuntimeException $e) {
        throw blog_uploads_error($e->getMessage());
    } finally {
        @unlink($tmp);
    }
    $meta = ['kind' => 'gpx', 'name' => F::safeName($name), 'time' => time()] + blog_gpx_line($gpx['points'], $gpx['times']);
    return blog_uploads_register('u' . bin2hex(random_bytes(8)), $meta);
}

function blog_uploads_register(string $id, array $meta): array
{
    F::write(blog_uploads_dir() . "/$id.json", json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $state = blog_uploads_state();
    $state['items'][$id] = $meta['kind'];
    $state['pending'][] = $id;
    blog_uploads_save($state);
    return ['id' => $id] + $meta;
}

/** The final file name an upload suggests: its name made safe, the extension of its type. */
function blog_uploads_name(string $name, string $ext): string
{
    $base = F::safeName(pathinfo($name, PATHINFO_FILENAME));
    return ($base === '' ? 'photo' : $base) . '.' . $ext;
}

/**
 * An upload of this chat with its metadata, "id" and "path" (the stored
 * file, or null when it is gone). Null for any id that does not match the
 * pattern or does not belong to this chat.
 */
function blog_uploads_meta(string $id): ?array
{
    $state = blog_uploads_state();
    if (!preg_match(BLOG_UPLOAD_ID, $id) || !isset($state['items'][$id]) || $state['folder'] === null) {
        return null;
    }
    $dir = blog_uploads_root() . '/' . $state['folder'];
    $meta = json_decode((string)@file_get_contents("$dir/$id.json"), true);
    if (!is_array($meta)) {
        return null;
    }
    $path = isset($meta['ext']) ? "$dir/$id.{$meta['ext']}" : null;
    return ['id' => $id, ...$meta, 'path' => $path !== null && is_file($path) ? $path : null];
}

/** Uploads waiting for the next message, as the Panel lists them. */
function blog_uploads_pending(): array
{
    $list = [];
    foreach (blog_uploads_state()['pending'] as $id) {
        $meta = blog_uploads_meta($id);
        if ($meta !== null) {
            $list[] = ['id' => $id, 'name' => $meta['name'], 'kind' => $meta['kind']];
        }
    }
    return $list;
}

/** Marks uploads as sent: they went with a message. */
function blog_uploads_sent(array $ids): void
{
    $state = blog_uploads_state();
    $state['pending'] = array_values(array_diff($state['pending'], $ids));
    blog_uploads_save($state);
}

/** What Claude gets with the message: the preview once, and the facts as text. */
function blog_uploads_blocks(string $id): array
{
    $meta = blog_uploads_meta($id);
    if ($meta === null) {
        return [];
    }
    if ($meta['kind'] === 'gpx') {
        $text = isset($meta['error'])
            ? "Upload $id (GPX, {$meta['name']}): not usable. {$meta['error']}"
            : "Upload $id (GPX, {$meta['name']}): {$meta['km']} km"
                . ($meta['seconds'] !== null ? ', ' . blog_route_duration($meta['seconds']) : '')
                . ', ' . count($meta['line']) . ' points, both ends cut. Line (GeoJSON, lon/lat): '
                . json_encode(['type' => 'LineString', 'coordinates' => $meta['line']]);
        return [['type' => 'text', 'text' => $text]];
    }
    $exif = implode(', ', array_map(fn ($key, $value) => "$key $value", array_keys($meta['exif']), $meta['exif']));
    return [
        ['type' => 'blog_image', 'file' => "$id.preview.jpg"],
        ['type' => 'text', 'text' => "Upload $id: file name {$meta['name']}, {$meta['width']} x {$meta['height']} px" . ($exif === '' ? '' : ", $exif") . '.'],
    ];
}

/**
 * The messages as the API takes them: every image block of the chat
 * ("blog_image", also inside tool results) gets its bytes from the folder.
 */
function blog_uploads_expand(array $messages): array
{
    $expand = function (array $blocks) use (&$expand): array {
        foreach ($blocks as $i => $block) {
            if (($block['type'] ?? '') === 'blog_image') {
                $data = blog_uploads_read((string)$block['file']);
                $blocks[$i] = $data === null
                    ? ['type' => 'text', 'text' => '(This image is no longer available.)']
                    : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode($data)]];
            } elseif (($block['type'] ?? '') === 'tool_result' && is_array($block['content'] ?? null)) {
                $blocks[$i]['content'] = $expand($block['content']);
            }
        }
        return $blocks;
    };
    foreach ($messages as $i => $message) {
        if (is_array($message['content'] ?? null)) {
            $messages[$i]['content'] = $expand($message['content']);
        }
    }
    return $messages;
}

/** An image file of this chat's folder, or null. */
function blog_uploads_read(string $file): ?string
{
    $folder = blog_uploads_state()['folder'];
    if ($folder === null || !preg_match(BLOG_UPLOAD_IMAGE, $file)) {
        return null;
    }
    $data = @file_get_contents(blog_uploads_root() . "/$folder/$file");
    return $data === false ? null : $data;
}

/** The thumbnail of an image upload as data URL for a card, or null. */
function blog_uploads_thumb(string $id): ?string
{
    $state = blog_uploads_state();
    if (!preg_match(BLOG_UPLOAD_ID, $id) || $state['folder'] === null) {
        return null;
    }
    $data = @file_get_contents(blog_uploads_root() . '/' . $state['folder'] . "/$id.thumb.jpg");
    return $data === false ? null : 'data:image/jpeg;base64,' . base64_encode($data);
}

/**
 * A JPEG of an image for Claude: upright, optionally cropped (fractions
 * x, y, w, h), its long edge at most $max pixels. Written into the chat's
 * folder; returns the file name.
 */
function blog_uploads_image(string $source, int $orientation = 1, ?array $crop = null, int $max = BLOG_UPLOAD_PREVIEW, ?string $name = null): string
{
    // GD holds about 5 bytes per pixel: a 64-megapixel photo needs about 320 MB.
    @ini_set('memory_limit', '1024M');
    $image = match (@getimagesize($source)[2] ?? null) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
        IMAGETYPE_PNG => @imagecreatefrompng($source),
        IMAGETYPE_WEBP => @imagecreatefromwebp($source),
        default => false,
    };
    if ($image === false) {
        throw new RuntimeException('The image cannot be read.');
    }
    $image = blog_uploads_orient($image, $orientation);
    if ($crop !== null) {
        $w = imagesx($image);
        $h = imagesy($image);
        $x = (int)round(max(0.0, min(1.0, (float)$crop['x'])) * $w);
        $y = (int)round(max(0.0, min(1.0, (float)$crop['y'])) * $h);
        $image = imagecrop($image, [
            'x' => $x,
            'y' => $y,
            'width' => max(1, min($w - $x, (int)round((float)$crop['w'] * $w))),
            'height' => max(1, min($h - $y, (int)round((float)$crop['h'] * $h))),
        ]) ?: throw new RuntimeException('The crop is empty.');
    }
    $long = max(imagesx($image), imagesy($image));
    if ($long > $max) {
        $image = imagescale($image, max(1, (int)round(imagesx($image) * $max / $long)), max(1, (int)round(imagesy($image) * $max / $long)), IMG_BICUBIC);
    }
    // Transparent areas become white, not black.
    $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
    imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
    imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
    $name ??= 'v' . bin2hex(random_bytes(8)) . '.jpg';
    imagejpeg($flat, blog_uploads_dir() . '/' . $name, 80);
    return $name;
}

/** Turns an image upright by its EXIF orientation (1 to 8). */
function blog_uploads_orient(GdImage $image, int $orientation): GdImage
{
    if (in_array($orientation, [2, 5, 7], true)) {
        imageflip($image, IMG_FLIP_HORIZONTAL);
    } elseif ($orientation === 4) {
        imageflip($image, IMG_FLIP_VERTICAL);
    }
    $angle = match ($orientation) {
        3 => 180,
        5, 8 => 90,
        6, 7 => 270,
        default => 0,
    };
    return $angle === 0 ? $image : imagerotate($image, $angle, 0);
}

/**
 * A JPEG without metadata segments, lossless: APP1 (EXIF, XMP), APP13
 * (IPTC) and the MPF index go, and whatever follows the end of the image
 * (motion photos, gain maps). Everything else stays byte for byte. An
 * orientation other than 1 comes back as an EXIF segment with only that tag.
 */
function blog_jpeg_strip(string $jpeg, int $orientation = 1): string
{
    if (!str_starts_with($jpeg, "\xFF\xD8")) {
        throw new RuntimeException('Not a JPEG');
    }
    $out = "\xFF\xD8";
    if ($orientation >= 2 && $orientation <= 8) {
        // TIFF, big endian: one IFD with one entry, Orientation (0x0112, SHORT).
        $tiff = "MM\x00\x2A\x00\x00\x00\x08\x00\x01" . pack('nnNn', 0x0112, 3, 1, $orientation) . "\x00\x00\x00\x00\x00\x00";
        $data = "Exif\x00\x00" . $tiff;
        $out .= "\xFF\xE1" . pack('n', strlen($data) + 2) . $data;
    }
    $pos = 2;
    $length = strlen($jpeg);
    while ($pos + 4 <= $length) {
        if ($jpeg[$pos] !== "\xFF") {
            throw new RuntimeException('Broken JPEG');
        }
        $marker = ord($jpeg[$pos + 1]);
        if ($marker === 0xFF) {
            $pos++;
            continue;
        }
        if ($marker === 0xDA) {
            // Start of scan: the image data up to its end marker stays as it is.
            $end = strpos($jpeg, "\xFF\xD9", $pos);
            if ($end === false) {
                throw new RuntimeException('JPEG without end');
            }
            return $out . substr($jpeg, $pos, $end + 2 - $pos);
        }
        $size = unpack('n', substr($jpeg, $pos + 2, 2))[1];
        if ($size < 2 || $pos + 2 + $size > $length) {
            throw new RuntimeException('Broken JPEG');
        }
        $segment = substr($jpeg, $pos, 2 + $size);
        $drop = $marker === 0xE1 || $marker === 0xED || ($marker === 0xE2 && substr($segment, 4, 4) === "MPF\x00");
        if (!$drop) {
            $out .= $segment;
        }
        $pos += 2 + $size;
    }
    throw new RuntimeException('JPEG without image data');
}

/** A PNG without text and EXIF chunks (eXIf, tEXt, zTXt, iTXt, tIME), lossless. */
function blog_png_strip(string $png): string
{
    $signature = "\x89PNG\r\n\x1a\n";
    if (!str_starts_with($png, $signature)) {
        throw new RuntimeException('Not a PNG');
    }
    $out = $signature;
    $pos = 8;
    $length = strlen($png);
    while ($pos + 12 <= $length) {
        $size = unpack('N', substr($png, $pos, 4))[1];
        $type = substr($png, $pos + 4, 4);
        if ($pos + 12 + $size > $length) {
            throw new RuntimeException('Broken PNG');
        }
        if (!in_array($type, ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'], true)) {
            $out .= substr($png, $pos, 12 + $size);
        }
        $pos += 12 + $size;
        if ($type === 'IEND') {
            return $out;
        }
    }
    throw new RuntimeException('PNG without end');
}

/** A WebP without EXIF and XMP chunks, lossless. */
function blog_webp_strip(string $webp): string
{
    if (substr($webp, 0, 4) !== 'RIFF' || substr($webp, 8, 4) !== 'WEBP') {
        throw new RuntimeException('Not a WebP');
    }
    $end = min(strlen($webp), 8 + unpack('V', substr($webp, 4, 4))[1]);
    $body = '';
    $pos = 12;
    while ($pos + 8 <= $end) {
        $type = substr($webp, $pos, 4);
        $size = unpack('V', substr($webp, $pos + 4, 4))[1];
        if ($pos + 8 + $size > $end) {
            throw new RuntimeException('Broken WebP');
        }
        $chunk = substr($webp, $pos, 8 + $size + ($size & 1));
        if ($type === 'VP8X') {
            // The flags of the extended format name EXIF (0x08) and XMP (0x04): they go with the chunks.
            $chunk[8] = chr(ord($chunk[8]) & ~0x0C);
        }
        if ($type !== 'EXIF' && $type !== 'XMP ') {
            $body .= $chunk;
        }
        $pos += 8 + $size + ($size & 1);
    }
    return 'RIFF' . pack('V', 4 + strlen($body)) . 'WEBP' . $body;
}

/**
 * Deletes what an applied card used: the stored file of an image (the
 * previews stay for the history of the chat) and all of a GPX upload.
 */
function blog_uploads_delete(string $id): void
{
    $meta = blog_uploads_meta($id);
    if ($meta === null) {
        return;
    }
    if ($meta['path'] !== null) {
        F::remove($meta['path']);
    }
    if ($meta['kind'] === 'gpx') {
        F::remove(blog_uploads_dir() . "/$id.json");
    }
}

/** Deletes a chat's folder: at "Neuer Chat" and at logout. */
function blog_uploads_remove(?string $folder): void
{
    if ($folder !== null && preg_match(BLOG_UPLOAD_FOLDER, $folder)) {
        Dir::remove(blog_uploads_root() . '/' . $folder);
    }
}

/** A new chat starts without uploads. */
function blog_uploads_reset(): void
{
    blog_uploads_remove(blog_uploads_state()['folder']);
    kirby()->session()->remove(BLOG_UPLOADS_SESSION);
}

/**
 * Runs with every request to the assistant. Folders of other chats go
 * 7 days after their last use. In this chat, uploads go 7 days after they
 * came, even when an open card needs them: such a card then finds them gone.
 */
function blog_uploads_cleanup(): void
{
    $root = blog_uploads_root();
    $current = blog_uploads_state()['folder'];
    $limit = time() - BLOG_UPLOAD_DAYS * 86400;
    if ($current !== null && is_dir("$root/$current")) {
        touch("$root/$current/.last");
    }
    foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $last = @filemtime($dir . '/.last') ?: (int)@filemtime($dir);
        if (basename($dir) !== $current && $last < $limit) {
            Dir::remove($dir);
        }
    }
    if ($current === null) {
        return;
    }
    foreach (glob("$root/$current/u*.json") ?: [] as $file) {
        $meta = json_decode((string)file_get_contents($file), true);
        if (($meta['time'] ?? 0) < $limit) {
            foreach (glob("$root/$current/" . basename($file, '.json') . '.*') ?: [] as $part) {
                F::remove($part);
            }
        }
    }
}
