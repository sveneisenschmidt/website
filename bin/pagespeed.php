<?php

/**
 * Measures the live site with PageSpeed Insights, the Google service that
 * runs Lighthouse against a public URL. The workflow Speed runs it after
 * every deploy. It tests three pages: the home page, the newest post (from
 * the sitemap) and the archive, each on a phone and on a desktop.
 *
 * The table holds the Lighthouse score (0 to 100) and the lab values:
 * FCP (first text or image), LCP (largest image or text block), TBT (time
 * the main thread was blocked) and CLS (how much the layout moved). "Field"
 * is the 75th percentile from real Chrome visitors over 28 days, when
 * Google has enough visits. Below the table each page lists the 3 changes
 * Lighthouse expects to save the most time.
 *
 * It only reports and never fails on a low score. It fails when the API
 * gives no result, so a broken measurement is visible.
 *
 * Environment: PAGESPEED_API_KEY (required), GITHUB_STEP_SUMMARY (optional:
 * the workflow shows this file on the page of the run).
 *
 * Usage: php bin/pagespeed.php <site-url>
 */

const PAGESPEED_API = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

/**
 * The newest post in a sitemap: its first URL with a date path. The
 * sitemap lists posts newest first (Website\Blog\Feeds::sitemap()).
 */
function pagespeed_newest_post(string $sitemap): ?string
{
    return preg_match('!<loc>([^<]+/\d{4}-\d{2}-\d{2}-[^<]*)</loc>!', $sitemap, $m) ? $m[1] : null;
}

/**
 * One row of the table from a PageSpeed Insights response.
 *
 * @param array<string, mixed> $result
 * @return array{score: int, fcp: string, lcp: string, tbt: string, cls: string, field: string, savings: list<string>}
 */
function pagespeed_row(array $result): array
{
    $audits = $result['lighthouseResult']['audits'];
    $value = fn(string $id) => (string) ($audits[$id]['displayValue'] ?? '?');
    $field = $result['loadingExperience']['metrics'] ?? [];
    $fieldText = $field === [] ? 'not enough visits' : implode(', ', array_filter([
        isset($field['LARGEST_CONTENTFUL_PAINT_MS']) ? 'LCP ' . round($field['LARGEST_CONTENTFUL_PAINT_MS']['percentile'] / 1000, 1) . ' s' : null,
        isset($field['INTERACTION_TO_NEXT_PAINT']) ? 'INP ' . $field['INTERACTION_TO_NEXT_PAINT']['percentile'] . ' ms' : null,
        isset($field['CUMULATIVE_LAYOUT_SHIFT_SCORE']) ? 'CLS ' . $field['CUMULATIVE_LAYOUT_SHIFT_SCORE']['percentile'] / 100 : null,
    ]));
    $savings = [];
    foreach ($audits as $audit) {
        $ms = (float) ($audit['details']['overallSavingsMs'] ?? 0);
        if ($ms > 0) {
            $savings[$audit['title']] = $ms;
        }
    }
    arsort($savings);
    return [
        'score' => (int) round(($result['lighthouseResult']['categories']['performance']['score'] ?? 0) * 100),
        'fcp' => $value('first-contentful-paint'),
        'lcp' => $value('largest-contentful-paint'),
        'tbt' => $value('total-blocking-time'),
        'cls' => $value('cumulative-layout-shift'),
        'field' => $fieldText,
        'savings' => array_map(fn(string $title, float $ms) => $title . ' (' . round($ms) . ' ms)', array_keys(array_slice($savings, 0, 3)), array_slice($savings, 0, 3)),
    ];
}

if (realpath($_SERVER['argv'][0] ?? '') !== __FILE__) {
    return;
}

$site = rtrim($argv[1] ?? '', '/');
$key = (string) getenv('PAGESPEED_API_KEY');
if (!preg_match('!^https://[^/]+$!', $site) || $key === '') {
    fwrite(STDERR, "Usage: PAGESPEED_API_KEY=... php bin/pagespeed.php <site-url>\n");
    exit(1);
}

$pages = ['Home' => "$site/", 'Archive' => "$site/archive/"];
$post = pagespeed_newest_post((string) @file_get_contents("$site/sitemap.xml"));
if ($post !== null) {
    $pages = ['Home' => "$site/", 'Newest post' => $post, 'Archive' => "$site/archive/"];
}

// All six runs at once: one run takes 15 to 40 s at Google.
$multi = curl_multi_init();
$handles = [];
foreach ($pages as $name => $url) {
    foreach (['mobile', 'desktop'] as $strategy) {
        $handle = curl_init(PAGESPEED_API . '?' . http_build_query(['url' => $url, 'strategy' => $strategy, 'category' => 'performance', 'key' => $key]));
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 180]);
        curl_multi_add_handle($multi, $handle);
        $handles["$name|$strategy"] = $handle;
    }
}
do {
    curl_multi_exec($multi, $running);
    curl_multi_select($multi, 1.0);
} while ($running > 0);

$lines = ["### PageSpeed Insights for $site", '', '| Page | Device | Score | FCP | LCP | TBT | CLS | Field (real visitors) |', '|---|---|---|---|---|---|---|---|'];
$details = [];
$failed = false;
foreach ($handles as $id => $handle) {
    [$name, $strategy] = explode('|', $id);
    $result = json_decode((string) curl_multi_getcontent($handle), true);
    curl_multi_remove_handle($multi, $handle);
    if (!is_array($result) || !isset($result['lighthouseResult'])) {
        $error = is_array($result) ? (string) ($result['error']['message'] ?? 'no result') : 'no answer';
        $lines[] = "| $name | $strategy | – | | | | | API error: " . substr($error, 0, 80) . ' |';
        $failed = true;
        continue;
    }
    $row = pagespeed_row($result);
    $lines[] = "| $name | $strategy | {$row['score']} | {$row['fcp']} | {$row['lcp']} | {$row['tbt']} | {$row['cls']} | {$row['field']} |";
    if ($row['savings'] !== []) {
        $details[] = "- $name, $strategy: " . implode('; ', $row['savings']);
    }
}
curl_multi_close($multi);
if ($details !== []) {
    $lines = [...$lines, '', 'Largest expected savings:', '', ...$details];
}
$report = implode("\n", $lines) . "\n";
echo $report;
$summary = getenv('GITHUB_STEP_SUMMARY');
if (is_string($summary) && $summary !== '') {
    file_put_contents($summary, $report, FILE_APPEND);
}
exit($failed ? 1 : 0);
