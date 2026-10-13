<?php

namespace Website\Blog;

use Kirby\Cms\File;
use Kirby\Cms\FileVersion;

/**
 * The thumbnails of the templates, all WebP.
 */
final class Images
{
    /**
     * A photo across the column: src at 1600 px, the srcset at 600, 1000 and
     * 1600 px, and its width and height at 1600 px for the layout.
     *
     * @return array{src: string, srcset: string, width: int, height: int}
     */
    public static function picture(File $file): array
    {
        $srcset = array_map(fn(int $width) => self::wide($file, $width)->url() . " {$width}w", [600, 1000, 1600]);
        // fitWidth() changes its object: a copy, so the file keeps its own size.
        $size = (clone $file->dimensions())->fitWidth(1600);
        return ['src' => self::wide($file, 1600)->url(), 'srcset' => implode(', ', $srcset), 'width' => $size->width(), 'height' => $size->height()];
    }

    /** The image $width px wide (never enlarged), WebP at quality 80. */
    public static function wide(File $file, int $width): FileVersion|File
    {
        return $file->thumb(['width' => $width, 'format' => 'webp', 'quality' => 80]);
    }

    /** A square of $edge px, WebP: from the middle, or from the focus point set in the Panel. */
    public static function square(File $file, int $edge, int $quality): FileVersion|File
    {
        return $file->thumb(['width' => $edge, 'height' => $edge, 'crop' => true, 'format' => 'webp', 'quality' => $quality]);
    }
}
