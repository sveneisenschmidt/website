<?php

namespace Website\Blog;

use Kirby\Cms\App;
use Kirby\Cms\Event;
use Kirby\Cms\File;
use Kirby\Cms\FileVersion;
use Kirby\Cms\Page;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use Throwable;
use WeakMap;
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
     * The square covers of tile() as [quality, small edge, large edge,
     * srcset]: `home` for the tiles on the front page, `archive` for the
     * grid of the photo archive, `list` for the small square in the
     * timeline and in the archive lists. A tile names the widths in srcset,
     * and the browser picks by the width of the layout. The small square is
     * 48 px wide in every layout and names the pixel density.
     */
    private const TILES = [
        'home' => [80, 240, 480, 'width'],
        'archive' => [85, 200, 400, 'width'],
        'list' => [80, 48, 96, 'density'],
    ];

    /**
     * The short side of the copy that render() makes the thumbnails from.
     * No thumbnail needs a larger source: picture() is at most 1600 px wide,
     * and the crops and the feed image are smaller.
     */
    private const SOURCE_SIDE = 1600;

    /**
     * The copy pays off from this number of thumbnails on. Measured locally
     * with a photo of 24 MP on 2026-10-14: 2 thumbnails took 0.42 s from the
     * original and 0.45 s with the copy, 3 took 0.56 s and 0.49 s.
     */
    private const COPY_FROM = 3;

    /** CPU seconds for the thumbnails of one image (render()). */
    private const TIME_LIMIT = 60;

    /**
     * Seconds after the first render() of a request in which render() still
     * starts work, the wait for the lock included. A web server ends a
     * request that sends no output for too long, mod_fcgid after 40 s by
     * default (FcgidIOTimeout). Thumbnails beyond the budget render on their
     * first request. The option blog.thumbs.budget sets another value; the
     * tests use it.
     */
    private const BUDGET = 20.0;

    /** @var WeakMap<App, float>|null The time of the first render() of each Kirby instance, for BUDGET. */
    private static ?WeakMap $started = null;

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
     * The <img> attributes of a square cover (TILES): src, srcset and the
     * edge for width and height.
     *
     * @param 'home'|'archive'|'list' $use
     * @return array{src: string, srcset: string, edge: int}
     */
    public static function tile(File $file, string $use): array
    {
        [$quality, $small, $large, $srcset] = self::TILES[$use];
        $one = self::square($file, $small, $quality)->url();
        $two = self::square($file, $large, $quality)->url();
        return $srcset === 'density'
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
     * Renders the missing thumbnails after a Kirby event that gives images
     * new thumbnail URLs or a page a new cover. plugins/blog calls it after
     * every page and file event. The URL of a thumbnail contains the page id, the file name and
     * the modification time of the file. A crop also contains the focus
     * point. Upload and replace run after the EXIF hooks of plugins/blog,
     * because Kirby runs the hooks with the exact name of an event before
     * the hooks with a wildcard.
     */
    public static function renderAfter(Event $event): void
    {
        $args = $event->arguments();
        switch ($event->name()) {
            case 'file.create:after':
                self::renderFile($args['file']);
                break;
            case 'file.replace:after':
            case 'file.changeName:after':
                self::renderFile($args['newFile']);
                break;
            case 'file.update:after':
                // Exif::store() saves every upload once before Exif::strip()
                // changes the file. That save keeps the focus point.
                if ($args['newFile']->focus()->value() !== $args['oldFile']->focus()->value()) {
                    self::renderFile($args['newFile']);
                }
                break;
            case 'page.changeSlug:after':
            case 'page.move:after':
                self::renderImages($args['newPage']);
                break;
            case 'page.duplicate:after':
                self::renderImages($args['duplicatePage']);
                break;
            case 'page.update:after':
                // A save can set a new cover. When its thumbnails exist,
                // render() checks them and returns.
                if ($args['newPage'] instanceof SitePage && ($cover = $args['newPage']->coverImage()) !== null) {
                    self::render($cover, true);
                }
                break;
        }
    }

    /**
     * Renders the thumbnails of a file of a page, with the sizes of a cover
     * when the file is the cover of its page. No template shows a file of
     * the site or of a user as a thumbnail, so these files get none.
     */
    public static function renderFile(File $file): void
    {
        $page = $file->page();
        if ($page instanceof SitePage) {
            self::render($file, $page->coverImage()?->id() === $file->id());
        }
    }

    /** renderFile() for every image of $page. */
    public static function renderImages(Page $page): void
    {
        foreach ($page->images() as $image) {
            self::renderFile($image);
        }
    }

    /**
     * Renders the thumbnails of picture() that do not exist yet. With
     * $cover, it also renders the squares of tile(), the feed image and the
     * link preview image. Kirby renders a thumbnail on the first request of
     * its URL. A photo in a post needs 3 thumbnails, a cover 8 more. A
     * thumbnail that this request cannot render within BUDGET keeps its job
     * file, and Kirby renders it on the first request.
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
        if (self::missing($versions) === []) {
            return;
        }
        $kirby = $file->kirby();
        self::$started ??= new WeakMap();
        self::$started[$kirby] ??= microtime(true);
        $left = (float) $kirby->option('blog.thumbs.budget', self::BUDGET) - (microtime(true) - self::$started[$kirby]);
        if ($left <= 0) {
            return;
        }
        Lock::run($kirby->root('cache') . '/.thumbs.lock', LOCK_EX, function () use ($kirby, $file, $versions): void {
            // Another request can have rendered them while this one waited.
            $versions = self::missing($versions);
            if ($versions === []) {
                return;
            }
            // set_time_limit() starts the count again. The assistant creates
            // a draft with all its photos in one request
            // (Website\Assistant\Apply), and GD time counts toward the limit.
            // The old limit comes back afterwards, with a new count; in the CLI
            // of the tests it is 0, no limit. A host can disable set_time_limit(); the @
            // hides the warning, and the limit of the host applies.
            $limit = (int) ini_get('max_execution_time');
            @set_time_limit(self::TIME_LIMIT);
            $source = count($versions) >= self::COPY_FROM ? self::source($kirby, $file) : null;
            try {
                foreach ($versions as $version) {
                    self::save($kirby, $version, $source ?? $file->root());
                }
            } finally {
                if ($source !== null) {
                    F::remove($source);
                }
                @set_time_limit($limit);
            }
        }, $left);
    }

    /**
     * The thumbnails of $versions that do not exist yet.
     *
     * @param list<FileVersion|File> $versions
     * @return array<int, FileVersion>
     */
    private static function missing(array $versions): array
    {
        return array_filter($versions, fn($version) => $version instanceof FileVersion && !is_file($version->root()));
    }

    /** A square of $edge px, WebP: from the middle, or from the focus point set in the Panel. */
    private static function square(File $file, int $edge, int $quality): FileVersion|File
    {
        return $file->thumb(['width' => $edge, 'height' => $edge, 'crop' => true, 'format' => 'webp', 'quality' => $quality]);
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
        $folder = $kirby->root('cache') . '/thumbs';
        // A copy older than one hour is left from a request that ended with
        // a fatal error, for example at the memory limit, before it could
        // remove its copy. render() takes at most TIME_LIMIT seconds of CPU
        // time per image.
        foreach (glob($folder . '/*.png') ?: [] as $old) {
            if (filemtime($old) < time() - 3600) {
                F::remove($old);
            }
        }
        // A square box of SOURCE_SIDE times the aspect ratio gives the short
        // side SOURCE_SIDE px, also for a photo that EXIF rotates.
        $dimensions = $file->dimensions();
        $long = max($dimensions->width(), $dimensions->height());
        $short = max(1, min($dimensions->width(), $dimensions->height()));
        $box = (int) ceil(self::SOURCE_SIDE * $long / $short);
        $path = $folder . '/' . bin2hex(random_bytes(6)) . '.png';
        try {
            Dir::make($folder);
            $kirby->thumb($file->root(), $path, ['width' => $box, 'height' => $box, 'format' => 'png', 'compression' => 0]);
            return $path;
        } catch (Throwable $e) {
            F::remove($path);
            error_log('blog thumbnail: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Renders a thumbnail from $source and removes its job file, as Kirby's
     * media route does (Kirby\Cms\Media::thumb()). A failed render must not
     * fail the upload or the save: the job file stays, and Kirby tries
     * again on the first request. Kirby renders into a temporary file and
     * removes it after a failure, so no broken thumbnail stays behind.
     */
    private static function save(App $kirby, FileVersion $version, string $source): void
    {
        try {
            $kirby->thumb($source, $version->root(), $version->modifications());
            F::remove(dirname($version->root()) . '/.jobs/' . basename($version->root()) . '.json');
        } catch (Throwable $e) {
            error_log('blog thumbnail: ' . $e->getMessage());
        }
    }
}
