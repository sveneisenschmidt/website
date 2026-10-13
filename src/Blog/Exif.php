<?php

namespace Website\Blog;

use Kirby\Cms\File;
use Throwable;

/**
 * EXIF of photos. Read once, on upload, and stored as file fields:
 * camera, lens, focal, aperture, shutter, iso. The values carry no units;
 * rows() formats them for the figure under a photo.
 */
final class Exif
{
    public const FIELDS = ['camera', 'lens', 'focal', 'aperture', 'shutter', 'iso'];

    /** Evaluate an EXIF rational such as "450/10". */
    private static function rational(mixed $v): ?float
    {
        if (is_array($v)) {
            $v = reset($v);
        }
        if ($v === null || $v === '') {
            return null;
        }
        if (is_string($v) && str_contains($v, '/')) {
            [$a, $b] = array_map('floatval', explode('/', $v, 2));
            return $b != 0.0 ? $a / $b : null;
        }
        return is_numeric($v) ? (float) $v : null;
    }

    /** "Canon EOS R6m2" from make "Canon" and model "Canon EOS R6m2": the make once. */
    public static function camera(string $make, string $model): string
    {
        $make = trim($make);
        $model = trim($model);
        if ($make !== '' && str_starts_with($model, $make)) {
            $model = ltrim(substr($model, strlen($make)));
        }
        return trim($make . ' ' . $model);
    }

    /** Shutter speed: "1/250" when 1/t is a whole number, otherwise seconds ("0.8", "2.5"). */
    public static function shutter(float $t): string
    {
        if ($t < 1) {
            $n = 1 / $t;
            if (abs($n - round($n)) < 0.05) {
                return '1/' . (int) round($n);
            }
        }
        return Text::number($t);
    }

    /**
     * File fields from the sections exif_read_data() returns. Missing or zero values are left out.
     *
     * @param array<string, mixed> $d
     * @return array<string, string>
     */
    public static function fields(array $d): array
    {
        $exif = $d['EXIF'] ?? [];
        $ifd0 = $d['IFD0'] ?? [];
        $out = [];

        $camera = self::camera((string) ($ifd0['Make'] ?? ''), (string) ($ifd0['Model'] ?? ''));
        if ($camera !== '') {
            $out['camera'] = $camera;
        }

        $lens = $exif['LensModel'] ?? $exif['UndefinedTag:0xA434'] ?? '';
        if (is_string($lens) && trim($lens) !== '') {
            $out['lens'] = trim($lens);
        }

        if (($f = self::rational($exif['FocalLength'] ?? null)) !== null && $f > 0) {
            $out['focal'] = Text::number($f);
        }

        if (($a = self::rational($exif['FNumber'] ?? null)) !== null && $a > 0) {
            $out['aperture'] = Text::number($a);
        }

        if (($t = self::rational($exif['ExposureTime'] ?? null)) !== null && $t > 0) {
            $out['shutter'] = self::shutter($t);
        }

        // The first of these tags the photo has. The Canon EOS R5 writes ISO
        // only in the tags 0x8832 (RecommendedExposureIndex) and 0x8833
        // (ISOSpeed), which PHP does not name.
        $iso = $exif['ISOSpeedRatings']
            ?? $exif['RecommendedExposureIndex'] ?? $exif['UndefinedTag:0x8832']
            ?? $exif['ISOSpeed'] ?? $exif['UndefinedTag:0x8833']
            ?? $exif['PhotographicSensitivity']
            ?? null;
        if (is_array($iso)) {
            $iso = reset($iso);
        }
        if (is_numeric($iso) && (int) $iso > 0) {
            $out['iso'] = (string) (int) $iso;
        }

        return $out;
    }

    /**
     * EXIF of an image file, as file fields.
     *
     * @return array<string, string>
     */
    public static function read(string $path): array
    {
        if (!function_exists('exif_read_data') || !is_file($path)) {
            return [];
        }
        return self::fields(@exif_read_data($path, null, true) ?: []);
    }

    /**
     * Store the EXIF of an uploaded image. On upload a value already in the
     * file's content wins. A replaced photo is a new file: all six fields come
     * from it, and a value the new file does not have is cleared.
     */
    public static function store(File $file, bool $replace = false): void
    {
        if ($file->type() !== 'image') {
            return;
        }
        $exif = self::read($file->root());
        $update = [];
        foreach (self::FIELDS as $key) {
            $value = $exif[$key] ?? null;
            if ($replace) {
                $update[$key] = $value;
            } elseif ($value !== null && $file->content()->get($key)->isEmpty()) {
                $update[$key] = $value;
            }
        }
        if ($update === []) {
            return;
        }
        try {
            $file->kirby()->impersonate('kirby', fn() => $file->update($update));
        } catch (Throwable $e) {
            // The upload itself worked; the fields can be filled in in the Panel.
            error_log('blog exif: ' . $e->getMessage());
        }
    }

    /**
     * The EXIF rows under an image: label => value, only the values the file has.
     *
     * @return array<string, string>
     */
    public static function rows(File $file): array
    {
        $value = fn(string $key) => trim((string) $file->content()->get($key)->value());
        $exposure = '';
        if (($shutter = $value('shutter')) !== '') {
            $exposure = str_starts_with($shutter, '1/') ? $shutter . 's' : sprintf('%.1fs', (float) $shutter);
        }
        if (($iso = $value('iso')) !== '') {
            $exposure = $exposure === '' ? "ISO $iso" : "$exposure · ISO $iso";
        }
        $aperture = $value('aperture');
        if ($aperture !== '') {
            $f = (float) $aperture;
            $aperture = floor($f) === $f ? sprintf('ƒ/%.0f', $f) : sprintf('ƒ/%.1f', $f);
        }
        return array_filter([
            'Camera' => $value('camera'),
            'Lens' => $value('lens'),
            'Focal length' => $value('focal') === '' ? '' : sprintf('%.0fmm', (float) $value('focal')),
            'Aperture' => $aperture,
            'Exposure' => $exposure,
        ], fn($v) => $v !== '');
    }
}
