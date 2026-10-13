<?php

namespace Website\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../bin/indexnow.php';
require_once __DIR__ . '/../../bin/warm.php';
require_once __DIR__ . '/../../bin/pagespeed.php';

/**
 * The parts of the scripts in bin/ that decide something without the
 * network: which sitemap URLs IndexNow gets, which links the warm-up
 * requests, and what the speed test reads from an answer of PageSpeed
 * Insights.
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

    public function testTheSpeedTestTakesTheNewestPostFromTheSitemap(): void
    {
        $sitemap = '<loc>https://example.com/</loc><loc>https://example.com/about/</loc>'
            . '<loc>https://example.com/2026-10-12-new/</loc><loc>https://example.com/2026-10-01-old/</loc>';

        $this->assertSame('https://example.com/2026-10-12-new/', pagespeed_newest_post($sitemap));
        $this->assertNull(pagespeed_newest_post('<loc>https://example.com/</loc>'));
    }

    public function testTheSpeedTestReadsScoreMetricsFieldDataAndSavings(): void
    {
        $audit = fn(string $value, float $savings = 0) => ['displayValue' => $value, 'title' => "Audit $value", 'details' => ['overallSavingsMs' => $savings]];
        $result = [
            'lighthouseResult' => [
                'categories' => ['performance' => ['score' => 0.87]],
                'audits' => [
                    'first-contentful-paint' => $audit('1.2 s'),
                    'largest-contentful-paint' => $audit('2.5 s'),
                    'total-blocking-time' => $audit('30 ms'),
                    'cumulative-layout-shift' => $audit('0'),
                    'render-blocking-resources' => $audit('a', 300),
                    'uses-responsive-images' => $audit('b', 900),
                    'unused-css-rules' => $audit('c', 120),
                    'unused-javascript' => $audit('d', 50),
                ],
            ],
            'loadingExperience' => ['metrics' => [
                'LARGEST_CONTENTFUL_PAINT_MS' => ['percentile' => 2140],
                'INTERACTION_TO_NEXT_PAINT' => ['percentile' => 80],
                'CUMULATIVE_LAYOUT_SHIFT_SCORE' => ['percentile' => 3],
            ]],
        ];

        $row = pagespeed_row($result);

        $this->assertSame(87, $row['score']);
        $this->assertSame('2.5 s', $row['lcp']);
        $this->assertSame('LCP 2.1 s, INP 80 ms, CLS 0.03', $row['field']);
        $this->assertSame(['Audit b (900 ms)', 'Audit a (300 ms)', 'Audit c (120 ms)'], $row['savings']);
    }
}
