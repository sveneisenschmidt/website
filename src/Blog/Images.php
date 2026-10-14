<?php

namespace Website\Blog;

use Closure;
use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\FileVersion;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use Throwable;
use Website\Models\SitePage;

/**
 * The thumbnails that templates and snippets show. Every thumbnail is WebP.
 * Each size is defined here once, and render() renders the same sizes after
 * an upload and after a save.
 */
final class Images
{
    /** The widths of picture(). */
    private const WIDTHS = [600, 1000, 1600];

    /**
     * The square covers of tile() as [quality, small edge, large edge]:
     * `home` for the tiles on the front page, `archive` for the grid of the
     * photo archive, `list` for the small square in the timeline and in the
     * archive lists.
     */
    private const TILES = [
        'home' => [80, 240, 480],
        'archive' => [85, 200, 400],
        'list' => [80, 48, 96],
    ];

    /**
     * The short side of the copy that render() makes the thumbnails from.
     * No thumbnail needs a larger source: picture() is at most 1600 px wide,
     * and the crops and the feed image are smaller.
     */
    private const SOURCE_SIDE = 1600;

    /** Seconds for the thumbnails of one image (render()). */
    private const TIME_LIMIT = 60;

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

    /**
     * The <img> attributes of a square cover: src, srcset and the edge for
     * width and height. A tile on the front page or in the archive names
     * the widths in srcset, and the browser picks by the width of the
     * layout. The small square of a list is 48 px wide in every layout and
     * names the pixel density.
     *
     * @param 'home'|'archive'|'list' $use
     * @return array{src: string, srcset: string, edge: int}
     */
    public static function tile(File $file, string $use): array
    {
        [$quality, $small, $large] = self::TILES[$use];
        $one = self::square($file, $small, $quality)->url();
        $two = self::square($file, $large, $quality)->url();
        return $use === 'list'
            ? ['src' => $one, 'srcset' => "$one 1x, $two 2x", 'edge' => $small]
            : ['src' => $two, 'srcset' => "$one {$small}w, $two {$large}w", 'edge' => $large];
    }

    /** The image $width px wide (never enlarged), WebP at quality 80. */
    public static function wide(File $file, int $width): FileVersion|File
    {
        return $file->thumb(['width' => $width, 'format' => 'webp', 'quality' => 80]);
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
     * render() for a file of a page, with the sizes of a cover when the
     * file is the cover of its page. No template shows a file of the site
     * or of a user as a thumbnail, so these files get none.
     */
    public static function renderFile(File $file): void
    {
        $page = $file->page();
        if ($page instanceof SitePage) {
            self::render($file, $page->coverImage()?->id() === $file->id());
        }
    }

    /**
     * Renders the thumbnails of picture() that do not exist yet. With
     * $cover, it also renders the squares of tile(), the feed image and the
     * link preview image. Kirby renders a thumbnail on the first request of
     * its URL. A photo in a post needs 3 thumbnails, a cover 8 more.
     * plugins/blog calls this after an upload and after a save, so the
     * first visitor gets files that exist.
     */
    public static function render(File $file, bool $cover = false): void
    {
        $versions = array_map(fn(int $width) => self::wide($file, $width), self::WIDTHS);
        if ($cover) {
            foreach (self::TILES as [$quality, $small, $large]) {
                $versions[] = self::square($file, $small, $quality);
                $versions[] = self::square($file, $large, $quality);
            }
            $versions[] = self::feed($file);
            $versions[] = self::share($file);
        }
        $missing = array_filter($versions, fn($version) => $version instanceof FileVersion && !is_file($version->root()));
        if ($missing === []) {
            return;
        }
        // set_time_limit() starts the count again. The assistant creates a
        // draft with all its photos in one request (Website\Assistant\Apply),
        // and GD time counts toward the limit. With the new count, each photo
        // gets TIME_LIMIT seconds. A host can disable set_time_limit(); the @
        // hides the warning, and the limit of the host applies.
        @set_time_limit(self::TIME_LIMIT);
        $kirby = $file->kirby();
        self::locked($kirby, function () use ($kirby, $file, $missing): void {
            // The copy pays off from two thumbnails on.
            $source = count($missing) > 1 ? self::source($kirby, $file) : null;
            try {
                foreach ($missing as $version) {
                    self::save($kirby, $version, $source ?? $file->root());
                }
            } finally {
                if ($source !== null) {
                    F::remove($source);
                }
            }
        });
    }

    /** A square of $edge px, WebP: from the middle, or from the focus point set in the Panel. */
    private static function square(File $file, int $edge, int $quality): FileVersion|File
    {
        return $file->thumb(['width' => $edge, 'height' => $edge, 'crop' => true, 'format' => 'webp', 'quality' => $quality]);
    }

    /**
     * Runs $render under an exclusive lock, so that one request at a time
     * renders thumbnails. The Panel uploads up to 20 files at once, and a
     * render holds a photo in memory: about 320 MB for 64 MP (Makefile,
     * target dev). The other uploads wait for the lock. The system releases
     * the lock when a request ends, also after a fatal error.
     *
     * @param Closure(): void $render
     */
    private static function locked(App $kirby, Closure $render): void
    {
        Dir::make($kirby->root('cache'));
        $lock = fopen($kirby->root('cache') . '/.thumbs.lock', 'c');
        if ($lock === false) {
            $render();
            return;
        }
        try {
            flock($lock, LOCK_EX);
            $render();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Renders a copy of the image with SOURCE_SIDE px on its short side
     * (never enlarged) as an uncompressed PNG, and returns its path. Returns
     * null when the copy fails; the thumbnails then read the original.
     * Reading the original takes the most time: a photo of 24 MP has about
     * 6 times the pixels of the copy, and on the server SrgbGd converts each
     * read to sRGB. With the copy, render() reads the original once.
     */
    private static function source(App $kirby, File $file): ?string
    {
        // A square box of SOURCE_SIDE times the aspect ratio gives the short
        // side SOURCE_SIDE px, also for a photo that EXIF rotates.
        $dimensions = $file->dimensions();
        $long = max($dimensions->width(), $dimensions->height());
        $short = max(1, min($dimensions->width(), $dimensions->height()));
        $box = (int) ceil(self::SOURCE_SIDE * $long / $short);
        $path = $kirby->root('cache') . '/thumbs/' . bin2hex(random_bytes(6)) . '.png';
        try {
            Dir::make(dirname($path));
            $kirby->thumb($file->root(), $path, ['width' => $box, 'height' => $box, 'format' => 'png', 'compression' => 0]);
            return $path;
        } catch (Throwable $e) {
            F::remove($path);
            error_log('blog thumbnail: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Renders a thumbnail from $source unless another request rendered it
     * while this one waited for the lock. Then it removes the job file, as
     * Kirby's media route does (Kirby\Cms\Media::thumb()). A failed render
     * must not fail the upload or the save: the job file stays, and Kirby
     * tries again on the first request. Kirby renders into a temporary file
     * and removes it after a failure, so no broken thumbnail stays behind.
     */
    private static function save(App $kirby, FileVersion $version, string $source): void
    {
        if (is_file($version->root())) {
            return;
        }
        try {
            $kirby->thumb($source, $version->root(), $version->modifications());
            F::remove(dirname($version->root()) . '/.jobs/' . basename($version->root()) . '.json');
        } catch (Throwable $e) {
            error_log('blog thumbnail: ' . $e->getMessage());
        }
    }
}
