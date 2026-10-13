<?php

use Kirby\Image\Darkroom;
use Kirby\Image\Darkroom\GdLib;

/**
 * Thumbnails with true colors. GD ignores embedded color profiles, so a
 * photo exported in Adobe RGB or Display P3 would look pale. Before GD
 * resizes it, such a photo is converted to sRGB with Imagick (lcms).
 * Photos without a profile or with an sRGB profile pass through unchanged.
 * Without Imagick (local Mac, CI) every photo passes through.
 *
 * sRGB2014.icc is the sRGB profile of the International Color Consortium
 * (registry.color.org), free to copy and embed.
 */
class BlogGd extends GdLib
{
    public function process(string $file, array $options = []): array
    {
        blog_to_srgb($file);
        return parent::process($file, $options);
    }
}

Darkroom::$types['blog-gd'] = BlogGd::class;

/**
 * Converts the image at $file to sRGB in place, if it has a color profile
 * other than sRGB. $file is Kirby's temporary copy, never the original.
 * Returns true if it converted.
 */
function blog_to_srgb(string $file): bool
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
    // Near lossless in between: GD encodes the final thumbnail.
    $image->setImageCompressionQuality(100);
    $image->setSamplingFactors(['1x1', '1x1', '1x1']);
    $image->writeImage($file);
    return true;
}
