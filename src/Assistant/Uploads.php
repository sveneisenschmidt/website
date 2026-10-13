<?php

namespace Website\Assistant;

use finfo;
use GdImage;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use RuntimeException;
use Throwable;
use Website\Blog\Exif;

/**
 * Stores the photos and GPX files that the Panel user adds to the chat. They
 * live in storage/assistant/ next to www/ and vendor/, outside the document
 * root, so no URL reaches them. Each chat has one folder with a random name,
 * which only the session knows. The server names every file itself, so a
 * file name from the browser never becomes a path. An image loses its
 * metadata on arrival (`Strip`). A GPX file is read and deleted at once, and
 * only the cut line stays (`Gpx`). Previews are files in the folder; the
 * session and the chat hold only their names. A card (a change that Claude
 * proposed, with buttons to apply or discard it) can use uploads until the
 * click.
 */
final class Uploads
{
    public const SESSION = 'blog.assistant.uploads';

    /** Accepted image types: MIME type from finfo => extension. */
    public const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public const MAX_BYTES = 60 * 1024 * 1024;
    public const MAX_PIXELS = 64000000;

    /** Long edge of a preview for Claude and of a thumbnail on a card, in pixels. */
    public const PREVIEW = 1568;
    public const THUMB = 240;

    /** `cleanup()` deletes uploads and unused chat folders after this many days. */
    public const DAYS = 7;

    /** Seconds the processing of one upload may take: a 64-megapixel photo and its previews are slow on a slow host. */
    public const TIME_LIMIT = 60;

    /**
     * Patterns for an upload id, for the image files that a message may name
     * ("u…" from an upload, "v…" from `image()`), and for a chat folder.
     */
    public const ID = '/^u[0-9a-f]{16}$/';
    public const IMAGE = '/^[uv][0-9a-f]{16}(?:\.preview)?\.jpg$/';
    public const FOLDER = '/^[0-9a-f]{32}$/';

    public static function root(): string
    {
        $root = (string) (option('blog.assistant.storage') ?? dirname(kirby()->root('index')) . '/storage/assistant');
        Dir::make($root);
        return $root;
    }

    /**
     * The uploads of this session's chat: folder, upload ids with their kind, ids not yet sent.
     *
     * @return array{folder: ?string, items: array<string, string>, pending: list<string>}
     */
    public static function state(): array
    {
        return kirby()->session()->get(self::SESSION) ?? ['folder' => null, 'items' => [], 'pending' => []];
    }

    /**
     * The folder of this chat, created on first use. Every call touches the
     * file .last, which `cleanup()` reads as the time of the last use.
     */
    public static function dir(): string
    {
        $state = Session::update(self::SESSION, self::state(...), function (array $state): array {
            $state['folder'] ??= bin2hex(random_bytes(16));
            return $state;
        });
        $dir = self::root() . '/' . $state['folder'];
        Dir::make($dir);
        touch($dir . '/.last');
        return $dir;
    }

    /**
     * Takes one uploaded file: checks it, stores it under a name that the
     * server chooses and marks it for the next message. Returns the stored
     * metadata. Throws `Error` with the reason that the Panel shows.
     *
     * @return array<string, mixed>
     */
    public static function accept(string $tmp, string $name): array
    {
        // A host can disable set_time_limit(); the @ hides the warning, and
        // the limit of the host applies.
        @set_time_limit(self::TIME_LIMIT);
        $size = (int) @filesize($tmp);
        if ($size === 0) {
            throw self::error('Die Datei ist leer.');
        }
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'gpx') {
            return self::acceptGpx($tmp, $name);
        }
        if ($size > self::MAX_BYTES) {
            throw self::error('Die Datei ist größer als ' . intdiv(self::MAX_BYTES, 1024 * 1024) . ' MB.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $ext = self::TYPES[$mime] ?? null;
        $info = @getimagesize($tmp);
        if ($ext === null || $info === false || image_type_to_mime_type($info[2]) !== $mime) {
            throw self::error('Nur JPEG, PNG, WebP und GPX.');
        }
        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
            throw self::error('Das Bild hat mehr als ' . intdiv(self::MAX_PIXELS, 1000000) . ' Megapixel.');
        }
        $bytes = (string) file_get_contents($tmp);
        $exif = [];
        $orientation = 1;
        try {
            if ($ext === 'jpg') {
                // Exif::read() keeps the camera data for the file fields. GPS
                // and all other metadata go with the removed segments.
                $exif = Exif::read($tmp);
                $orientation = (int) ((@exif_read_data($tmp) ?: [])['Orientation'] ?? 1);
                $bytes = Strip::jpeg($bytes, $orientation);
            } else {
                $bytes = $ext === 'png' ? Strip::png($bytes) : Strip::webp($bytes);
            }
        } catch (RuntimeException) {
            throw self::error('Die Datei ist beschädigt.');
        }
        // Every request of the chat sends every image again, so a chat takes
        // at most `Chat::IMAGES` images and `Chat::IMAGE_BYTES` of image data.
        $chat = Session::load();
        $load = Chat::imageLoad($chat->messages, $chat->images);
        if ($load['count'] >= Chat::IMAGES) {
            throw self::error('Dieser Chat hat schon ' . Chat::IMAGES . ' Bilder. Für weitere beginn einen neuen Chat.');
        }
        $id = 'u' . bin2hex(random_bytes(8));
        $dir = self::dir();
        $turned = $orientation >= 5 && $orientation <= 8;
        $meta = [
            'kind' => 'image',
            'ext' => $ext,
            'name' => self::name($name, $ext),
            'width' => $turned ? $height : $width,
            'height' => $turned ? $width : $height,
            'orientation' => $orientation,
            'exif' => $exif,
            'time' => time(),
        ];
        try {
            F::write("$dir/$id.$ext", $bytes);
            self::image("$dir/$id.$ext", $orientation, null, self::PREVIEW, "$id.preview.jpg");
            // The thumbnail comes from the upright preview, so the large
            // original is decoded only once.
            self::image("$dir/$id.preview.jpg", 1, null, self::THUMB, "$id.thumb.jpg");
            if ($load['bytes'] + self::base64((int) filesize("$dir/$id.preview.jpg")) > Chat::IMAGE_BYTES) {
                throw self::error('Die Bilder dieses Chats sind zusammen zu groß für eine Anfrage an Claude. Für weitere beginn einen neuen Chat.');
            }
            return self::register($id, $meta);
        } catch (Throwable $e) {
            // Any failure after the first write deletes all files of this
            // upload, so no half upload stays. This includes a PHP \Error
            // from GD or from the metadata.
            array_map(fn($file) => F::remove($file), glob("$dir/$id.*") ?: []);
            throw $e instanceof Error ? $e : self::error('Das Bild lässt sich nicht lesen.');
        }
    }

    /**
     * An upload of this chat with its metadata, "id" and "path" (the stored
     * file, or null when it is gone). Null for any id that does not match
     * `ID` or does not belong to this chat.
     *
     * @return ?array<string, mixed>
     */
    public static function meta(string $id): ?array
    {
        $state = self::state();
        if (!preg_match(self::ID, $id) || !isset($state['items'][$id]) || $state['folder'] === null) {
            return null;
        }
        $dir = self::root() . '/' . $state['folder'];
        $meta = json_decode((string) @file_get_contents("$dir/$id.json"), true);
        if (!is_array($meta)) {
            return null;
        }
        $path = isset($meta['ext']) ? "$dir/$id.{$meta['ext']}" : null;
        return ['id' => $id, ...$meta, 'path' => $path !== null && is_file($path) ? $path : null];
    }

    /**
     * Uploads waiting for the next message, as the Panel lists them.
     *
     * @return list<array{id: string, name: string, kind: string}>
     */
    public static function pending(): array
    {
        $list = [];
        foreach (self::state()['pending'] as $id) {
            $meta = self::meta($id);
            if ($meta !== null) {
                $list[] = ['id' => $id, 'name' => $meta['name'], 'kind' => $meta['kind']];
            }
        }
        return $list;
    }

    /**
     * Marks uploads as sent with a message, so they no longer wait for the next one.
     *
     * @param list<string> $ids
     */
    public static function sent(array $ids): void
    {
        Session::update(self::SESSION, self::state(...), function (array $state) use ($ids): array {
            $state['pending'] = array_values(array_diff($state['pending'], $ids));
            return $state;
        });
    }

    /**
     * What Claude gets with the message: the preview once, and the facts as text.
     *
     * @return list<array<string, string>>
     */
    public static function blocks(string $id): array
    {
        $meta = self::meta($id);
        if ($meta === null) {
            return [];
        }
        if ($meta['kind'] === 'gpx') {
            // Without its file name: a name like "Home to the lake" tells where a ride began.
            $text = isset($meta['error'])
                ? "Upload $id (GPX): not usable. {$meta['error']}"
                : "Upload $id (GPX): {$meta['km']} km"
                    . ($meta['seconds'] !== null ? ', ' . Route::duration($meta['seconds']) : '')
                    . ', ' . count($meta['line']) . ' points, both ends cut. Line (GeoJSON, lon/lat): '
                    . json_encode(['type' => 'LineString', 'coordinates' => $meta['line']]);
            return [['type' => 'text', 'text' => $text]];
        }
        $exif = implode(', ', array_map(fn($key, $value) => "$key $value", array_keys($meta['exif']), $meta['exif']));
        return [
            ['type' => 'blog_image', 'file' => "$id.preview.jpg"],
            ['type' => 'text', 'text' => "Upload $id: file name {$meta['name']}, {$meta['width']} x {$meta['height']} px" . ($exif === '' ? '' : ", $exif") . '.'],
        ];
    }

    /**
     * The messages as the API takes them. The chat stores an image as a block
     * of its own type "blog_image" with a file name, also inside tool
     * results. This replaces each such block with the image data from the
     * folder, so the session never holds image data.
     *
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    public static function expand(array $messages): array
    {
        $expand = function (array $blocks) use (&$expand): array {
            foreach ($blocks as $i => $block) {
                if (($block['type'] ?? '') === 'blog_image') {
                    $data = self::read((string) $block['file']);
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
    public static function read(string $file): ?string
    {
        $path = self::file($file, self::IMAGE);
        $data = $path === null ? false : @file_get_contents($path);
        return $data === false ? null : $data;
    }

    /** The size of an image file of this chat's folder in bytes, 0 when it is gone. */
    public static function size(string $file): int
    {
        $path = self::file($file, self::IMAGE);
        return $path === null ? 0 : (int) @filesize($path);
    }

    /** The size of $bytes bytes in base64, the encoding of images in a request to Claude. */
    public static function base64(int $bytes): int
    {
        return 4 * (int) ceil($bytes / 3);
    }

    /** The thumbnail of an image upload as data URL for a card, or null. */
    public static function thumb(string $id): ?string
    {
        $path = self::file($id, self::ID);
        $data = $path === null ? false : @file_get_contents("$path.thumb.jpg");
        return $data === false ? null : 'data:image/jpeg;base64,' . base64_encode($data);
    }

    /**
     * A JPEG of an image for Claude: upright, optionally cropped (fractions
     * x, y, w, h), its long edge at most $max pixels. Written into the chat's
     * folder; returns the file name.
     *
     * @param ?array<string, mixed> $crop
     */
    public static function image(string $source, int $orientation = 1, ?array $crop = null, int $max = self::PREVIEW, ?string $name = null): string
    {
        // GD holds about 5 bytes per pixel: a 64-megapixel photo needs about 320 MB.
        @ini_set('memory_limit', '1024M');
        $info = @getimagesize($source);
        $image = match ($info === false ? null : $info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => @imagecreatefromwebp($source),
            default => false,
        };
        if ($image === false) {
            throw new RuntimeException('The image cannot be read.');
        }
        $image = self::orient($image, $orientation);
        if ($crop !== null) {
            $w = imagesx($image);
            $h = imagesy($image);
            $x = (int) round(max(0.0, min(1.0, (float) $crop['x'])) * $w);
            $y = (int) round(max(0.0, min(1.0, (float) $crop['y'])) * $h);
            $image = self::gd(imagecrop($image, [
                'x' => $x,
                'y' => $y,
                'width' => max(1, min($w - $x, (int) round((float) $crop['w'] * $w))),
                'height' => max(1, min($h - $y, (int) round((float) $crop['h'] * $h))),
            ]), 'imagecrop');
        }
        $long = max(imagesx($image), imagesy($image));
        if ($long > $max) {
            // imagecopyresampled, not imagescale: imagescale with IMG_BICUBIC
            // returns false on some GD builds (Homebrew PHP 8.5).
            $w = max(1, (int) round(imagesx($image) * $max / $long));
            $h = max(1, (int) round(imagesy($image) * $max / $long));
            $scaled = self::gd(imagecreatetruecolor($w, $h), 'imagecreatetruecolor');
            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);
            self::gd(imagecopyresampled($scaled, $image, 0, 0, 0, 0, $w, $h, imagesx($image), imagesy($image)), 'imagecopyresampled');
            $image = $scaled;
        }
        // JPEG has no transparency. Without a white background, transparent
        // areas turn black.
        $flat = self::gd(imagecreatetruecolor(imagesx($image), imagesy($image)), 'imagecreatetruecolor');
        self::gd(imagefill($flat, 0, 0, self::gd(imagecolorallocate($flat, 255, 255, 255), 'imagecolorallocate')), 'imagefill');
        self::gd(imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image)), 'imagecopy');
        $name ??= 'v' . bin2hex(random_bytes(8)) . '.jpg';
        self::gd(@imagejpeg($flat, self::dir() . '/' . $name, 80), 'imagejpeg');
        return $name;
    }

    /**
     * Deletes what an applied card used: the stored file of an image (the
     * previews stay for the history of the chat) and all of a GPX upload.
     */
    public static function delete(string $id): void
    {
        $meta = self::meta($id);
        if ($meta === null) {
            return;
        }
        if ($meta['path'] !== null) {
            F::remove($meta['path']);
        }
        if ($meta['kind'] === 'gpx') {
            F::remove(self::dir() . "/$id.json");
        }
    }

    /** Deletes a chat's folder: at "Neuer Chat" and at logout. */
    public static function remove(?string $folder): void
    {
        if ($folder !== null && preg_match(self::FOLDER, $folder)) {
            Dir::remove(self::root() . '/' . $folder);
        }
    }

    /** Deletes the uploads of the current chat, for "Neuer Chat". */
    public static function reset(): void
    {
        self::remove(self::state()['folder']);
        kirby()->session()->remove(self::SESSION);
    }

    /**
     * Runs before every API request of the assistant (`Panel::api()`). It
     * deletes the folders of other chats 7 days after their last use and
     * leaves folders with other names alone. In the current chat it deletes
     * uploads 7 days after they arrived, even when an open card needs them.
     * Such a card then fails with a message, and a note tells Claude which
     * uploads are gone.
     */
    public static function cleanup(): void
    {
        $root = self::root();
        $current = self::state()['folder'];
        $limit = time() - self::DAYS * 86400;
        if ($current !== null && is_dir("$root/$current")) {
            touch("$root/$current/.last");
        }
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $last = @filemtime($dir . '/.last') ?: (int) @filemtime($dir);
            if (preg_match(self::FOLDER, basename($dir)) && basename($dir) !== $current && $last < $limit) {
                Dir::remove($dir);
            }
        }
        if ($current === null) {
            return;
        }
        $gone = [];
        foreach (glob("$root/$current/u*.json") ?: [] as $file) {
            $meta = json_decode((string) file_get_contents($file), true);
            if (($meta['time'] ?? 0) < $limit) {
                foreach (glob("$root/$current/" . basename($file, '.json') . '.*') ?: [] as $part) {
                    F::remove($part);
                }
                $gone[] = basename($file, '.json');
            }
        }
        if ($gone !== []) {
            Session::update(self::SESSION, self::state(...), function (array $state) use ($gone): array {
                $state['pending'] = array_values(array_diff($state['pending'], $gone));
                return $state;
            });
            Session::note('(Uploads deleted ' . self::DAYS . ' days after they came, no longer available: ' . implode(', ', $gone) . '.)');
        }
    }

    private static function error(string $text): Error
    {
        return new Error('upload', $text . ' Nichts geändert.');
    }

    /**
     * A GPX file: read, deleted at once; only the cut line and its numbers stay.
     *
     * @return array<string, mixed>
     */
    private static function acceptGpx(string $tmp, string $name): array
    {
        try {
            $gpx = Gpx::read($tmp);
        } catch (RuntimeException $e) {
            throw self::error($e->getMessage());
        } finally {
            @unlink($tmp);
        }
        $meta = ['kind' => 'gpx', 'name' => F::safeName($name), 'time' => time()] + Gpx::line($gpx['points'], $gpx['times']);
        return self::register('u' . bin2hex(random_bytes(8)), $meta);
    }

    /**
     * Stores the metadata of an upload and marks it for the next message.
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private static function register(string $id, array $meta): array
    {
        // EXIF texts in an encoding other than UTF-8 would make json_encode()
        // fail. JSON_INVALID_UTF8_SUBSTITUTE replaces the invalid bytes and
        // keeps the readable part.
        F::write(self::dir() . "/$id.json", json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
        Session::update(self::SESSION, self::state(...), function (array $state) use ($id, $meta): array {
            $state['items'][$id] = $meta['kind'];
            $state['pending'][] = $id;
            return $state;
        });
        return ['id' => $id] + $meta;
    }

    /** The final file name an upload suggests: its name made safe, the extension of its type. */
    private static function name(string $name, string $ext): string
    {
        $base = F::safeName(pathinfo($name, PATHINFO_FILENAME));
        return ($base === '' ? 'photo' : $base) . '.' . $ext;
    }

    /**
     * The path of $name in this chat's folder. Null without a folder, or when
     * $name does not match $pattern. The name comes from Claude or the Panel,
     * and the pattern keeps the path inside the folder.
     */
    private static function file(string $name, string $pattern): ?string
    {
        $folder = self::state()['folder'];
        return $folder !== null && preg_match($pattern, $name) ? self::root() . "/$folder/$name" : null;
    }

    /**
     * Returns the result of a GD call. GD reports a failure as false, and
     * this turns it into an exception.
     *
     * @template T
     * @param T|false $result
     * @return T
     */
    private static function gd(mixed $result, string $call): mixed
    {
        if ($result === false) {
            throw new RuntimeException("GD: $call failed.");
        }
        return $result;
    }

    /** Turns an image upright by its EXIF orientation (1 to 8). */
    private static function orient(GdImage $image, int $orientation): GdImage
    {
        if (in_array($orientation, [2, 5, 7], true)) {
            self::gd(imageflip($image, IMG_FLIP_HORIZONTAL), 'imageflip');
        } elseif ($orientation === 4) {
            self::gd(imageflip($image, IMG_FLIP_VERTICAL), 'imageflip');
        }
        $angle = match ($orientation) {
            3 => 180,
            5, 8 => 90,
            6, 7 => 270,
            default => 0,
        };
        return $angle === 0 ? $image : self::gd(imagerotate($image, $angle, 0), 'imagerotate');
    }
}
