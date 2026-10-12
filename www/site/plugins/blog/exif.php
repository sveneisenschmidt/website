<?php

use Kirby\Cms\File;

/**
 * EXIF of photos. Read once, on import and on upload, and stored as file
 * fields: camera, lens, focal, aperture, shutter, iso. The values carry no
 * units; the templates format them.
 */

const BLOG_EXIF_FIELDS = ['camera', 'lens', 'focal', 'aperture', 'shutter', 'iso'];

/** Evaluate an EXIF rational such as "450/10". */
function blog_rational($v): ?float
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
    return is_numeric($v) ? (float)$v : null;
}

/** A number without trailing zeros: 2.80 -> "2.8", 45.0 -> "45". */
function blog_num(float $v): string
{
    return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
}

/** "Canon EOS R6m2" from make "Canon" and model "Canon EOS R6m2": the make once, as Hugo showed it. */
function blog_camera(string $make, string $model): string
{
    $make = trim($make);
    $model = trim($model);
    if ($make !== '' && str_starts_with($model, $make)) {
        $model = ltrim(substr($model, strlen($make)));
    }
    return trim($make . ' ' . $model);
}

/** Shutter speed: "1/250" when 1/t is a whole number, otherwise seconds ("0.8", "2.5"). */
function blog_shutter(float $t): string
{
    if ($t < 1) {
        $n = 1 / $t;
        if (abs($n - round($n)) < 0.05) {
            return '1/' . (int)round($n);
        }
    }
    return blog_num($t);
}

/** File fields from the sections exif_read_data() returns. Missing or zero values are left out. */
function blog_exif_fields(array $d): array
{
    $exif = $d['EXIF'] ?? [];
    $ifd0 = $d['IFD0'] ?? [];
    $out = [];

    $camera = blog_camera((string)($ifd0['Make'] ?? ''), (string)($ifd0['Model'] ?? ''));
    if ($camera !== '') {
        $out['camera'] = $camera;
    }

    $lens = $exif['LensModel'] ?? $exif['UndefinedTag:0xA434'] ?? '';
    if (is_string($lens) && trim($lens) !== '') {
        $out['lens'] = trim($lens);
    }

    if (($f = blog_rational($exif['FocalLength'] ?? null)) !== null && $f > 0) {
        $out['focal'] = blog_num($f);
    }

    if (($a = blog_rational($exif['FNumber'] ?? null)) !== null && $a > 0) {
        $out['aperture'] = blog_num($a);
    }

    if (($t = blog_rational($exif['ExposureTime'] ?? null)) !== null && $t > 0) {
        $out['shutter'] = blog_shutter($t);
    }

    // In Hugo's order. The Canon EOS R5 writes ISO only in the tags 0x8832
    // (RecommendedExposureIndex) and 0x8833 (ISOSpeed), which PHP does not name.
    $iso = $exif['ISOSpeedRatings']
        ?? $exif['RecommendedExposureIndex'] ?? $exif['UndefinedTag:0x8832']
        ?? $exif['ISOSpeed'] ?? $exif['UndefinedTag:0x8833']
        ?? $exif['PhotographicSensitivity']
        ?? null;
    if (is_array($iso)) {
        $iso = reset($iso);
    }
    if (is_numeric($iso) && (int)$iso > 0) {
        $out['iso'] = (string)(int)$iso;
    }

    return $out;
}

/** EXIF of an image file, as file fields. */
function blog_read_exif(string $path): array
{
    if (!function_exists('exif_read_data') || !is_file($path)) {
        return [];
    }
    return blog_exif_fields(@exif_read_data($path, null, true) ?: []);
}

/**
 * Store the EXIF of an uploaded image. On upload a value already in the
 * file's content wins. A replaced photo is a new file: all six fields come
 * from it, and a value the new file does not have is cleared.
 */
function blog_store_exif(File $file, bool $replace = false): void
{
    if ($file->type() !== 'image') {
        return;
    }
    $exif = blog_read_exif($file->root());
    $update = [];
    foreach (BLOG_EXIF_FIELDS as $key) {
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
        $file->kirby()->impersonate('kirby', fn () => $file->update($update));
    } catch (Throwable $e) {
        // The upload itself worked; the fields can be filled in in the Panel.
        error_log('blog_store_exif: ' . $e->getMessage());
    }
}
