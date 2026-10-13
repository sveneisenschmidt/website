<?php

namespace Website\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../bin/indexnow.php';
require_once __DIR__ . '/../../bin/warm.php';

/**
 * The parts of the scripts in bin/ that decide something without the
 * network: which sitemap URLs IndexNow gets, and which links the warm-up
 * requests.
 */
final class BinScriptsTest extends TestCase
{
    public function testIndexNowGetsOnlyTheUrlsChangedSinceTheGivenTime(): void
    {
        $sitemap = "<urlset>\n"
            . "  <url>\n    <loc>https://example.com/</loc>\n    <lastmod>2026-10-13T10:00:00+02:00</lastmod>\n  </url>\n"
            . "  <url>\n    <loc>https://example.com/2026-10-01-old/</loc>\n    <lastmod>2026-10-01T10:00:00+02:00</lastmod>\n  </url>\n"
            . "  <url>\n    <loc>https://example.com/2026-10-12-new/</loc>\n    <lastmod>2026-10-12T22:00:00+02:00</lastmod>\n  </url>\n"
            . "</urlset>\n";

        [$urls, $total] = indexnow_changed($sitemap, (int) strtotime('2026-10-12T12:00:00+02:00'));

        $this->assertSame(['https://example.com/', 'https://example.com/2026-10-12-new/'], $urls);
        $this->assertSame(3, $total);
    }

    public function testTheWarmUpFollowsPagesAndThumbnailsOfTheSiteOnly(): void
    {
        $html = '<a href="https://example.com/archive/">Archive</a>'
            . '<a href="/2026-10-12-new/#top">Post</a>'
            . '<a href="/search/?q=bike">Search</a>'
            . '<a href="https://other.example/x/">Other site</a>'
            . '<a href="/index.xml">Feed</a>'
            . '<a href="/panel">Panel</a>'
            . '<img src="/media/pages/p/a1/photo-600x-q80.webp" srcset="/media/pages/p/a1/photo-600x-q80.webp 600w, /media/pages/p/a1/photo-1600x-q80.webp 1600w">'
            . '<a href="/media/pages/p/a1/photo.jpg">Original</a>';

        [$pages, $thumbnails] = warm_links($html, 'https://example.com');

        $this->assertSame(['/archive/', '/2026-10-12-new/'], $pages);
        $this->assertSame(['/media/pages/p/a1/photo-600x-q80.webp', '/media/pages/p/a1/photo-1600x-q80.webp'], $thumbnails);
    }
}
