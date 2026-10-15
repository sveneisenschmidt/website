<?php

namespace Website\Analytics;

use Kirby\Cms\Page;
use Website\Models\PostPage;

/**
 * Adds up the visits for the Panel view "Besucher" (plugins/analytics) and
 * for the statistics on About (snippets/stats.php). The days from before
 * Hits existed are in Months. A day can have numbers in both, so Totals
 * adds them.
 *
 * @phpstan-import-type Day from Months
 * @phpstan-type PageRow array{id: string, title: string, url: ?string, visits: int}
 * @phpstan-type OriginRow array{host: string, visits: int}
 */
final class Totals
{
    /**
     * Totals for the Panel over the last $days days, today included. Every
     * day has its own pages and origins, so the Panel can show one day.
     *
     * @return array{
     *     days: list<array{date: string, visits: int, views: int, pages: list<PageRow>, referrers: list<OriginRow>}>,
     *     totals: array{today: int, week: int, month: int, views: int},
     *     pages: list<PageRow>,
     *     referrers: list<OriginRow>,
     *     bots: array{named: int, suspect: int, names: list<array{name: string, hits: int}>},
     * }
     */
    public static function summary(int $days = 30): array
    {
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $today = date('Y-m-d');
        $byDate = self::days($from, $today);
        $list = [];
        $pages = [];
        $referrers = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $day = $byDate[$date] ?? [];
            $list[] = [
                'date' => $date,
                'visits' => $day['visits'] ?? 0,
                'views' => $day['views'] ?? 0,
                'pages' => self::pages($day['pages'] ?? []),
                'referrers' => self::origins($day['referrers'] ?? []),
            ];
            foreach ($day['pages'] ?? [] as $id => $n) {
                $pages[$id] = ($pages[$id] ?? 0) + $n;
            }
            foreach ($day['referrers'] ?? [] as $host => $n) {
                $referrers[$host] = ($referrers[$host] ?? 0) + $n;
            }
        }
        $sum = fn(array $days) => array_sum(array_column($days, 'visits'));
        return [
            'days' => $list,
            'totals' => [
                'today' => end($list)['visits'],
                'week' => $sum(array_slice($list, -7)),
                'month' => $sum($list),
                'views' => array_sum(array_column($list, 'views')),
            ],
            'pages' => self::pages($pages),
            'referrers' => self::origins($referrers),
            'bots' => self::bots($from, $today),
        ];
    }

    /**
     * The totals of all months for the statistics on About: visits, views,
     * the number of pages, and the five posts with the most visits (id => visits).
     *
     * @return array{visits: int, views: int, pages: int, top: array<string, int>}
     */
    public static function all(): array
    {
        $first = min([...Months::all(), ...Hits::months(), date('Y-m')]);
        $visits = 0;
        $views = 0;
        $pages = [];
        foreach (self::days("$first-01", date('Y-m-d')) as $day) {
            $visits += $day['visits'] ?? 0;
            $views += $day['views'] ?? 0;
            foreach ($day['pages'] ?? [] as $id => $n) {
                $pages[$id] = ($pages[$id] ?? 0) + $n;
            }
        }
        $posts = array_filter($pages, fn($id) => page($id) instanceof PostPage, ARRAY_FILTER_USE_KEY);
        arsort($posts);
        return ['visits' => $visits, 'views' => $views, 'pages' => count($pages), 'top' => array_slice($posts, 0, 5, true)];
    }

    /**
     * The statistics on About: visits, views, pages, and the most visited
     * posts as [post, visits]. Null before the first visit.
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
     * Title and public URL of a counted page. A deleted page keeps its id
     * with "(gelöscht)" (deleted) as its title and has no URL.
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

    /**
     * The 20 pages with the most visits, with title and URL.
     *
     * @param array<string, int> $visits
     * @return list<PageRow>
     */
    private static function pages(array $visits): array
    {
        arsort($visits);
        return array_map(fn($id) => [...self::page((string) $id), 'visits' => $visits[$id]], array_slice(array_keys($visits), 0, 20));
    }

    /**
     * The 10 origins with the most visits.
     *
     * @param array<string, int> $visits
     * @return list<OriginRow>
     */
    private static function origins(array $visits): array
    {
        arsort($visits);
        return array_map(fn($host) => ['host' => (string) $host, 'visits' => $visits[$host]], array_slice(array_keys($visits), 0, 10));
    }

    /**
     * The days from $from to $to from Months and Hits, keyed by date.
     *
     * @return array<string, Day>
     */
    private static function days(string $from, string $to): array
    {
        $days = [];
        foreach (Months::all() as $month) {
            if ($month < substr($from, 0, 7) || $month > substr($to, 0, 7)) {
                continue;
            }
            foreach (Months::read($month) as $date => $day) {
                if ($date >= $from && $date <= $to) {
                    $days[$date] = $day;
                }
            }
        }
        foreach (Hits::days($from, $to) as $date => $day) {
            $days[$date] = self::add($days[$date] ?? [], $day);
        }
        return $days;
    }

    /**
     * @param Day $a
     * @param Day $b
     * @return Day
     */
    private static function add(array $a, array $b): array
    {
        $sum = function (array $x, array $y): array {
            foreach ($y as $key => $n) {
                $x[$key] = ($x[$key] ?? 0) + $n;
            }
            return $x;
        };
        return [
            'visits' => ($a['visits'] ?? 0) + ($b['visits'] ?? 0),
            'views' => ($a['views'] ?? 0) + ($b['views'] ?? 0),
            'pages' => $sum($a['pages'] ?? [], $b['pages'] ?? []),
            'referrers' => $sum($a['referrers'] ?? [], $b['referrers'] ?? []),
        ];
    }

    /**
     * The requests of bots from $from to $to: the requests of the bots that
     * name themselves, also per name, and the requests in the group
     * Kind::SUSPECT.
     *
     * @return array{named: int, suspect: int, names: list<array{name: string, hits: int}>}
     */
    private static function bots(string $from, string $to): array
    {
        $names = [];
        $suspect = 0;
        foreach (Hits::agents($from, $to) as $row) {
            $kind = Kind::of($row['agent'], $row['fetchDest'], $row['language']);
            if ($kind === Kind::BOT) {
                $name = Kind::botName($row['agent']);
                $names[$name] = ($names[$name] ?? 0) + $row['hits'];
            } elseif ($kind === Kind::SUSPECT) {
                $suspect += $row['hits'];
            }
        }
        arsort($names);
        return [
            'named' => array_sum($names),
            'suspect' => $suspect,
            'names' => array_map(fn($name) => ['name' => (string) $name, 'hits' => $names[$name]], array_keys($names)),
        ];
    }
}
