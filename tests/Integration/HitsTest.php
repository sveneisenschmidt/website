<?php

namespace Website\Tests\Integration;

use PDO;
use Pdo\Sqlite;
use Website\Analytics\Fingerprint;
use Website\Analytics\Hits;

/**
 * @phpstan-import-type Hit from Hits
 */
final class HitsTest extends IntegrationTestCase
{
    private const SAFARI = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15';

    public function testARowKeepsTheRequestAsItCame(): void
    {
        $hit = $this->hit(query: '?source=rss&utm_medium=social', referer: 'https://kagi.com/smallweb');
        $hit['headers']['sec-ch-ua'] = '"Chromium";v="141"';
        Hits::add($hit);

        $statement = (new Sqlite('sqlite:' . $this->root . '/content/.analytics/2026-10.sqlite'))
            ->query('SELECT time, ip_hash, net_hash, method, uri, protocol, page, status, session, headers, agent, visitor, referer, fetch_dest, language, purpose FROM hits');
        $this->assertNotFalse($statement);

        $this->assertSame([
            'time' => '2026-10-15 10:00:00.000',
            'ip_hash' => 'ip-a',
            'net_hash' => 'net-a',
            'method' => 'GET',
            'uri' => '/photos/?source=rss&utm_medium=social',
            'protocol' => 'HTTP/2.0',
            'page' => 'photos',
            'status' => 200,
            'session' => 0,
            'headers' => json_encode($hit['headers'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'agent' => self::SAFARI,
            'visitor' => 'ip-a ' . self::SAFARI,
            'referer' => 'https://kagi.com/smallweb',
            'fetch_dest' => 'document',
            'language' => 'de-DE',
            'purpose' => null,
        ], $statement->fetch(PDO::FETCH_ASSOC));
    }

    public function testAHitGoesIntoTheDatabaseOfItsMonth(): void
    {
        Hits::add($this->hit(time: '2026-09-30 23:59:59.999'));
        Hits::add($this->hit(time: '2026-10-01 00:00:00.000'));

        $this->assertSame(['2026-09', '2026-10'], Hits::months());
        $this->assertSame(['2026-10-01'], array_keys(Hits::days('2026-10-01', '2026-10-31')));
        $this->assertSame(['2026-09-30', '2026-10-01'], array_keys(Hits::days('2026-09-30', '2026-10-01')));
    }

    public function testDaysCountPageViewsOfBrowsersOnly(): void
    {
        Hits::add($this->hit());
        Hits::add($this->hit(page: 'about'));
        Hits::add($this->hit(visitor: 'b', agent: 'curl/8.7.1', fetchDest: null, language: null));
        Hits::add($this->hit(visitor: 'c', fetchDest: null));
        Hits::add($this->hit(visitor: 'd', language: ''));
        Hits::add($this->hit(visitor: 'e', purpose: 'prefetch'));
        Hits::add($this->hit(visitor: 'f', method: 'HEAD'));
        Hits::add($this->hit(visitor: 'g', status: 404, page: null));
        Hits::add($this->hit(visitor: 'h', fetchDest: 'empty', page: null));
        Hits::add($this->hit(visitor: 'i', session: true));

        $this->assertSame(
            ['2026-10-15' => ['visits' => 1, 'views' => 2, 'pages' => ['about' => 1, 'photos' => 1], 'referrers' => []]],
            Hits::days('2026-10-15', '2026-10-15'),
        );
    }

    public function testAVisitorIsOneAddressWithOneBrowserOnOneDay(): void
    {
        Hits::add($this->hit());
        Hits::add($this->hit(agent: self::SAFARI . ' Other'));
        Hits::add($this->hit(time: '2026-10-16 09:00:00.000'));

        $days = Hits::days('2026-10-15', '2026-10-16');

        $this->assertSame(2, $days['2026-10-15']['visits']);
        $this->assertSame(1, $days['2026-10-16']['visits']);
    }

    public function testTheFirstViewOfADayNamesTheOriginOfAVisit(): void
    {
        Hits::add($this->hit(referer: 'https://www.kagi.com/', query: '?source=rss'));
        Hits::add($this->hit(page: 'about', referer: 'https://instagram.com/'));
        Hits::add($this->hit(visitor: 'b', query: '?source=rss'));
        Hits::add($this->hit(visitor: 'c', referer: 'http://localhost/'));
        Hits::add($this->hit(visitor: 'd', query: '?source=other'));
        Hits::add($this->hit(time: '2026-10-16 09:00:00.000', referer: 'https://instagram.com/'));

        $days = Hits::days('2026-10-15', '2026-10-16');

        $this->assertSame(['RSS' => 1, 'kagi.com' => 1], $days['2026-10-15']['referrers']);
        $this->assertSame(['instagram.com' => 1], $days['2026-10-16']['referrers']);
    }

    public function testOwnAddressesAndNetworksCountForNothing(): void
    {
        foreach (['198.51.100.7', '2001:db8:1:2::abcd', '203.0.113.9'] as $i => $ip) {
            Hits::add($this->hit(visitor: "v$i", ipHash: Fingerprint::ip($ip), netHash: Fingerprint::net($ip)));
            Hits::add($this->hit(visitor: "b$i", ipHash: Fingerprint::ip($ip), netHash: Fingerprint::net($ip), agent: 'curl/8.7.1', fetchDest: null, language: null));
        }
        $this->assertSame(3, Hits::days('2026-10-15', '2026-10-15')['2026-10-15']['visits']);

        $this->kirby->impersonate('kirby', fn() => $this->kirby->site()->update(['ownIps' => "198.51.100.7\n2001:db8:1:2::/64"]));

        $this->assertSame(1, Hits::days('2026-10-15', '2026-10-15')['2026-10-15']['visits']);
        $this->assertSame(1, array_sum(array_column(array_filter(Hits::agents('2026-10-15', '2026-10-15'), fn($row) => $row['agent'] === 'curl/8.7.1'), 'hits')));
    }

    public function testAgentsCountRequestsPerUserAgentAndHeaders(): void
    {
        Hits::add($this->hit());
        Hits::add($this->hit(visitor: 'b', agent: 'curl/8.7.1', fetchDest: null, language: null));
        Hits::add($this->hit(visitor: 'c', agent: 'curl/8.7.1', fetchDest: null, language: null));
        Hits::add($this->hit(visitor: 'd', fetchDest: null));

        $this->assertEqualsCanonicalizing([
            ['agent' => self::SAFARI, 'fetchDest' => true, 'language' => true, 'hits' => 1],
            ['agent' => self::SAFARI, 'fetchDest' => false, 'language' => true, 'hits' => 1],
            ['agent' => 'curl/8.7.1', 'fetchDest' => false, 'language' => false, 'hits' => 2],
        ], Hits::agents('2026-10-15', '2026-10-15'));
    }

    public function testAnUnreadableMonthIsLoggedAndSkipped(): void
    {
        Hits::add($this->hit(time: '2026-09-30 10:00:00.000'));
        file_put_contents($this->root . '/content/.analytics/2026-10.sqlite', 'broken');
        // phpunit.xml fails a test on any output, so the log goes to a file here.
        $log = $this->root . '/php-errors.log';
        $previous = ini_set('error_log', $log);

        try {
            $days = Hits::days('2026-09-01', '2026-10-31');
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $this->assertSame(['2026-09-30'], array_keys($days));
        $this->assertStringContainsString('blog analytics: ', (string) file_get_contents($log));
        $this->assertSame('broken', file_get_contents($this->root . '/content/.analytics/2026-10.sqlite'));
    }

    /**
     * A stored request. $visitor stands in for the fingerprints of address
     * and network, unless $ipHash and $netHash are given.
     *
     * @return Hit
     */
    private function hit(
        string $visitor = 'a',
        ?string $ipHash = null,
        ?string $netHash = null,
        string $time = '2026-10-15 10:00:00.000',
        string $method = 'GET',
        ?string $page = 'photos',
        int $status = 200,
        bool $session = false,
        string $query = '',
        string $agent = self::SAFARI,
        ?string $referer = null,
        ?string $fetchDest = 'document',
        ?string $language = 'de-DE',
        ?string $purpose = null,
    ): array {
        return [
            'time' => $time,
            'ip_hash' => $ipHash ?? "ip-$visitor",
            'net_hash' => $netHash ?? "net-$visitor",
            'method' => $method,
            'uri' => '/' . ($page ?? 'missing') . '/' . $query,
            'protocol' => 'HTTP/2.0',
            'page' => $page,
            'status' => $status,
            'session' => $session,
            'headers' => array_filter([
                'accept-language' => $language,
                'referer' => $referer,
                'sec-fetch-dest' => $fetchDest,
                'sec-purpose' => $purpose,
                'user-agent' => $agent,
            ], fn($value) => $value !== null),
        ];
    }
}
