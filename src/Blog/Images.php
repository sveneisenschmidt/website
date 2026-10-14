<?php

namespace Website\Blog;

use Kirby\Cms\File;
use Kirby\Cms\FileVersion;
use Kirby\Filesystem\F;
use Throwable;

/**
 * The thumbnails that templates and snippets show. Every thumbnail is WebP.
 */
final class Images
{
    /** The widths of picture(). */
    private const WIDTHS = [600, 1000, 1600];

    /**
     * The squares of a cover as [edge, quality]: the front page
     * (templates/home.php), the photo archive (snippets/months.php) and the
     * timeline (snippets/thumb.php).
     */
    private const SQUARES = [[240, 80], [480, 80], [200, 85], [400, 85], [48, 80], [96, 80]];

    /**
     * The <img> attributes of a photo at the full column width: src at
     * 1600 px, srcset at 600, 1000 and 1600 px. Width and height are those
     * of the 1600 px version, so the browser reserves the space before the
     * image loads.
     *
     * @return array{src: string, srcset: string, width: int, height: int}
     */
    public static function picture(File $file): array
    {
        $srcset = array_map(fn(int $width) => self::wide($file, $width)->url() . " {$width}w", self::WIDTHS);
        // fitWidth() changes the object it is called on, and the file keeps its
        // dimensions object for later calls. The clone keeps the file's size intact.
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

    /** The cover in the feed: the image fits into 768 x 768 px. */
    public static function feed(File $file): FileVersion|File
    {
        return $file->thumb(['width' => 768, 'height' => 768, 'format' => 'webp', 'quality' => 80]);
    }

    /** The link preview image, cropped to 1200 x 630 px, the size that Facebook recommends for og:image. */
    public static function share(File $file): FileVersion|File
    {
        return $file->thumb(['width' => 1200, 'height' => 630, 'crop' => true, 'format' => 'webp', 'quality' => 80]);
    }

    /**
     * Renders the thumbnails of picture() now. With $cover, it also renders
     * the squares, the feed image and the link preview image. Kirby renders
     * a thumbnail on the first request of its URL. A photo in a post needs
     * 3 thumbnails, a cover 8 more. plugins/blog calls this after an upload
     * and after a save, so the first visitor gets files that exist.
     */
    public static function render(File $file, bool $cover = false): void
    {
        $versions = array_map(fn(int $width) => self::wide($file, $width), self::WIDTHS);
        if ($cover) {
            foreach (self::SQUARES as [$edge, $quality]) {
                $versions[] = self::square($file, $edge, $quality);
            }
            $versions[] = self::feed($file);
            $versions[] = self::share($file);
        }
        foreach ($versions as $version) {
            self::save($version);
        }
    }

    /**
     * Renders a thumbnail that does not exist yet and removes its job file,
     * as Kirby's media route does (Kirby\Cms\Media::thumb()). A failed render
     * must not fail the upload or the save: the job file stays, and Kirby
     * tries again on the first request.
     */
    private static function save(FileVersion|File $version): void
    {
        if (!$version instanceof FileVersion || is_file($version->root())) {
            return;
        }
        try {
            $version->save();
            F::remove(dirname($version->root()) . '/.jobs/' . basename($version->root()) . '.json');
        } catch (Throwable $e) {
            F::remove($version->root());
            error_log('blog thumbnail: ' . $e->getMessage());
        }
    }
}
