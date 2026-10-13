<?php

namespace Website\Analytics;

use Kirby\Cms\Page;
use Website\Models\PostPage;

/**
 * The counted days added up: for the Panel view "Besucher" and for the
 * visitor numbers on About (snippets/stats.php).
 */
final class Totals
{
    /**
     * Totals for the Panel over the last $days days, today included.
     *
     * @return array{
     *     days: list<array{date: string, visits: int, views: int}>,
     *     totals: array{today: int, week: int, month: int, views: int},
     *     pages: list<array{id: string, title: string, url: ?string, visits: int}>,
     *     referrers: list<array{host: string, visits: int}>,
     * }
     */
    public static function summary(int $days = 30): array
    {
        $months = [];
        $list = [];
        $pages = [];
        $referrers = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $months[substr($date, 0, 7)] ??= Months::read(substr($date, 0, 7));
            $day = $months[substr($date, 0, 7)][$date] ?? [];
            $list[] = ['date' => $date, 'visits' => $day['visits'] ?? 0, 'views' => $day['views'] ?? 0];
            foreach ($day['pages'] ?? [] as $id => $n) {
                $pages[$id] = ($pages[$id] ?? 0) + $n;
            }
            foreach ($day['referrers'] ?? [] as $host => $n) {
                $referrers[$host] = ($referrers[$host] ?? 0) + $n;
            }
        }
        arsort($pages);
        arsort($referrers);
        $sum = fn(array $days) => array_sum(array_column($days, 'visits'));
        return [
            'days' => $list,
            'totals' => [
                'today' => end($list)['visits'],
                'week' => $sum(array_slice($list, -7)),
                'month' => $sum($list),
                'views' => array_sum(array_column($list, 'views')),
            ],
            'pages' => array_map(
                fn($id) => [...self::page($id), 'visits' => $pages[$id]],
                array_slice(array_keys($pages), 0, 20),
            ),
            'referrers' => array_map(
                fn($host) => ['host' => $host, 'visits' => $referrers[$host]],
                array_slice(array_keys($referrers), 0, 10),
            ),
        ];
    }

    /**
     * All counted days together, for the block on About: visits, views, the
     * number of pages and the five posts with the most visits (id => visits).
     *
     * @return array{visits: int, views: int, pages: int, top: array<string, int>}
     */
    public static function all(): array
    {
        $visits = 0;
        $views = 0;
        $pages = [];
        foreach (Months::all() as $month) {
            foreach (Months::read($month) as $day) {
                $visits += $day['visits'] ?? 0;
                $views += $day['views'] ?? 0;
                foreach ($day['pages'] ?? [] as $id => $n) {
                    $pages[$id] = ($pages[$id] ?? 0) + $n;
                }
            }
        }
        $posts = array_filter($pages, fn($id) => page($id) instanceof PostPage, ARRAY_FILTER_USE_KEY);
        arsort($posts);
        return ['visits' => $visits, 'views' => $views, 'pages' => count($pages), 'top' => array_slice($posts, 0, 5, true)];
    }

    /**
     * The visitor numbers on About: visits, views, pages,
     * and the most visited posts as [post, visits]. Null before the first visit.
     *
     * @return array{visits: int, views: int, pages: int, top: list<array{0: Page, 1: int}>}|null
     */
    public static function stats(): ?array
    {
        $totals = self::all();
        if ($totals['visits'] === 0) {
            return null;
        }
        $top = [];
        foreach ($totals['top'] as $id => $visits) {
            $top[] = [page($id), $visits];
        }
        return ['top' => $top] + $totals;
    }

    /**
     * Title and public URL of a counted page, also for deleted pages.
     *
     * @return array{id: string, title: string, url: ?string}
     */
    public static function page(string $id): array
    {
        $page = page($id);
        if ($page === null) {
            return ['id' => $id, 'title' => "$id (gelöscht)", 'url' => null];
        }
        return ['id' => $id, 'title' => $page->isHomePage() ? 'Startseite' : $page->title()->value(), 'url' => $page->url()];
    }
}
