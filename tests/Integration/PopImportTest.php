<?php

namespace Website\Tests\Integration;

use PDO;

/**
 * Pop's visits as daily totals of "Besucher" (bin/lib/pop.php), from a
 * small database with Pop's table.
 */
final class PopImportTest extends IntegrationTestCase
{
    private string $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kirby->impersonate('kirby', fn () => $this->kirby->site()->createChild(['slug' => 'home', 'template' => 'home', 'draft' => false, 'content' => ['title' => 'Home']]));
        blog_redirects_write([['from' => '/posts/daily-photo-test/', 'to' => '/2026-10-07-test-photo/', 'pop' => 'keep', 'rss' => 'keep']]);

        $this->db = $this->root . '/data.db';
        $pdo = new PDO('sqlite:' . $this->db);
        $pdo->exec('CREATE TABLE visits (id INTEGER PRIMARY KEY AUTOINCREMENT, fingerprint TEXT NOT NULL, page_id TEXT NOT NULL, visited_at INTEGER NOT NULL)');
        $insert = $pdo->prepare('INSERT INTO visits (fingerprint, page_id, visited_at) VALUES (?, ?, ?)');
        foreach ([
            ['a', 'https://sven.eisenschmidt.website/2026-10-07-test-photo/', '2026-03-01 10:00'],
            ['a', 'https://sven.eisenschmidt.website/2026-10-07-test-photo/', '2026-03-01 11:00'],
            ['b', 'https://sven.eisenschmidt.website/posts/daily-photo-test/', '2026-03-01 23:30'],
            ['b', 'https://sven.eisenschmidt.website/', '2026-03-02 00:30'],
            ['c', 'https://sven.eisenschmidt.website/gone/', '2026-03-02 09:00'],
            ['c', 'https://hypo.eisenschmidt.website/', '2026-03-02 09:00'],
        ] as [$fingerprint, $url, $time]) {
            $insert->execute([$fingerprint, $url, strtotime("$time Europe/Berlin")]);
        }
    }

    public function testDailyTotalsPerPage(): void
    {
        $result = pop_import($this->db);

        $this->assertSame(6, $result['rows']);
        $this->assertSame(2, $result['days']);
        $this->assertSame(['https://sven.eisenschmidt.website/gone/' => 1, 'https://hypo.eisenschmidt.website/' => 1], $result['unknown']);
        // Days in Berlin time; an old URL counts for the post it leads to.
        $this->assertSame([
            '2026-03-01' => ['visits' => 2, 'views' => 3, 'pages' => ['photos/test-photo' => 2], 'referrers' => []],
            '2026-03-02' => ['visits' => 1, 'views' => 1, 'pages' => ['home' => 1], 'referrers' => []],
        ], blog_analytics_month('2026-03'));
    }

    public function testADayKirbyCountedStays(): void
    {
        $kirby = ['visits' => 9, 'views' => 9, 'pages' => ['home' => 9], 'referrers' => []];
        blog_analytics_write(blog_analytics_dir() . '/2026-03.json', ['2026-03-02' => $kirby]);

        $result = pop_import($this->db);

        $this->assertSame(1, $result['days']);
        $this->assertSame(1, $result['kept']);
        $this->assertSame($kirby, blog_analytics_month('2026-03')['2026-03-02']);
        $this->assertSame(['2026-03-01', '2026-03-02'], array_keys(blog_analytics_month('2026-03')));
    }
}
