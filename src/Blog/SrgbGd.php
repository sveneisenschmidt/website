<?php

namespace Website\Blog;

use Imagick;
use Kirby\Image\Darkroom\GdLib;

/**
 * Kirby's GD thumbnail driver with a color conversion before the resize.
 * GD ignores embedded color profiles, so a photo exported in Adobe RGB or
 * Display P3 would look pale. Such a photo is first converted to sRGB with
 * Imagick, which uses the Little CMS library for it. A photo without a
 * profile or with an sRGB profile stays unchanged. Without the Imagick
 * extension (local Mac, CI) every photo stays unchanged. plugins/blog
 * registers the class as the thumbs driver `blog-gd`, and config.php
 * selects it.
 *
 * sRGB2014.icc is the sRGB profile of the International Color Consortium
 * (registry.color.org). Its license allows copying and embedding it.
 */
class SrgbGd extends GdLib
{
    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function process(string $file, array $options = []): array
    {
        self::toSrgb($file);
        return parent::process($file, $options);
    }

    /**
     * Converts the image at $file to sRGB in place when it has a color
     * profile other than sRGB. Returns true after a conversion. $file is the
     * temporary copy that Kirby makes for each thumbnail, never the original.
     */
    public static function toSrgb(string $file): bool
    {
        if (!class_exists('Imagick')) {
            return false;
        }
        $probe = new Imagick();
        $probe->pingImage($file);
        if ($probe->getImageProfiles('icc', false) === []) {
            return false;
        }
        if (stripos($probe->getImageProperty('icc:description') ?: '', 'srgb') !== false) {
            return false;
        }

        $image = new Imagick($file);
        $image->profileImage('icc', file_get_contents(__DIR__ . '/sRGB2014.icc'));
        // This file is only an intermediate step: GD reads it and encodes the
        // final thumbnail. Quality 100 and no chroma subsampling (1x1) keep
        // the loss of this extra encoding small.
        $image->setImageCompressionQuality(100);
        $image->setSamplingFactors(['1x1', '1x1', '1x1']);
        $image->writeImage($file);
        return true;
    }
}
