<?php

namespace Website\Tests\Integration;

use Website\Analytics\AccessLog;
use Website\Analytics\Hits;
use Website\Tests\Support\TestSite;

/**
 * @phpstan-import-type Hit from Hits
 */
final class AccessLogTest extends IntegrationTestCase
{
    private const SAFARI = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15';

    protected function setUp(): void
    {
        parent::setUp();
        TestSite::countries($this->root);
    }

    public function testARowGetsTheCountryOfTheNetworkInItsLogLine(): void
    {
        Hits::add($this->hit('a', '10:00:00.400'));
        Hits::add($this->hit('b', '10:00:00.400', uri: '/about'));

        $result = AccessLog::fill([$this->log([
            $this->line('203.0.0.0', '10:00:00'),
            $this->line('198.51.0.0', '10:00:00', uri: '/about'),
        ])]);

        $this->assertSame(['missing' => 2, 'filled' => 2], $result);
        $this->assertSame(['AT', 'DE'], $this->countries());
    }

    public function testALogLineOneSecondApartMatches(): void
    {
        Hits::add($this->hit('a', '10:00:01.020'));
        Hits::add($this->hit('b', '10:00:05.900', uri: '/about'));

        AccessLog::fill([$this->log([
            $this->line('198.51.0.0', '10:00:00'),
            $this->line('203.0.0.0', '10:00:06', uri: '/about'),
        ])]);

        $this->assertSame(['DE', 'AT'], $this->countries());
    }

    public function testMethodStatusAndUserAgentMustMatch(): void
    {
        Hits::add($this->hit('a', '10:00:00.000'));

        AccessLog::fill([$this->log([
            $this->line('198.51.0.0', '10:00:00', method: 'HEAD'),
            $this->line('198.51.0.0', '10:00:00', status: 304),
            $this->line('198.51.0.0', '10:00:00', agent: 'curl/8.7.1'),
            $this->line('198.51.0.0', '10:00:03'),
        ])]);

        $this->assertSame([null], $this->countries());
    }

    public function testClickIdsAndAMissingUserAgentMatch(): void
    {
        Hits::add($this->hit('a', '10:00:00.000', uri: '/?fbclid=&utm_source=ig'));
        Hits::add($this->hit('b', '10:00:00.000', agent: ''));

        AccessLog::fill([$this->log([
            $this->line('198.51.0.0', '10:00:00', uri: '/?fbclid=PAZXh0bg&utm_source=ig'),
            $this->line('203.0.0.0', '10:00:00', agent: '-'),
        ])]);

        $this->assertSame(['DE', 'AT'], $this->countries());
    }

    public function testRowsOfOneAddressShareTheNetworkThatFitsAllOfThem(): void
    {
        // Two visitors open the same page in the same second: the line of
        // that second fits both networks. The second request of address a
        // fits only one.
        Hits::add($this->hit('a', '10:00:00.000'));
        Hits::add($this->hit('b', '10:00:00.000'));
        Hits::add($this->hit('a', '10:00:10.000', uri: '/about'));

        AccessLog::fill([$this->log([
            $this->line('198.51.0.0', '10:00:00'),
            $this->line('203.0.0.0', '10:00:00'),
            $this->line('198.51.0.0', '10:00:10', uri: '/about'),
        ])]);

        $this->assertSame(['DE', null, 'DE'], $this->countries());
    }

    public function testRowsOfOneNetworkShareTheNetworkToo(): void
    {
        Hits::add($this->hit('a', '10:00:00.000', net: 'n'));
        Hits::add($this->hit('b', '10:00:00.000'));
        Hits::add($this->hit('c', '10:00:10.000', uri: '/about', net: 'n'));

        AccessLog::fill([$this->log([
            $this->line('198.51.0.0', '10:00:00'),
            $this->line('203.0.0.0', '10:00:00'),
            $this->line('198.51.0.0', '10:00:10', uri: '/about'),
        ])]);

        $this->assertSame(['DE', null, 'DE'], $this->countries());
    }

    public function testSeveralNetworksInOneCountryGiveThatCountry(): void
    {
        Hits::add($this->hit('a', '10:00:00.000'));

        AccessLog::fill([$this->log([
            $this->line('203.0.0.0', '10:00:00'),
            $this->line('2001:db8::', '10:00:00'),
            $this->line('2001:db8:1::', '10:00:00'),
        ])]);
        $this->assertSame([null], $this->countries());

        Hits::add($this->hit('b', '10:00:20.000'));
        AccessLog::fill([$this->log([
            $this->line('2001:db8::', '10:00:20'),
            $this->line('2001:db8:1::', '10:00:20'),
        ])]);
        $this->assertSame([null, 'NL'], $this->countries());
    }

    public function testAnAddressWithACountryFromTheCounterGivesItToItsOlderRows(): void
    {
        Hits::add($this->hit('a', '10:00:00.000'));
        Hits::add($this->hit('a', '2026-10-16T09:00:00.000+02:00', country: 'FR'));

        $result = AccessLog::fill([$this->log([$this->line('198.51.0.0', '10:00:00')])]);

        $this->assertSame(['missing' => 1, 'filled' => 1], $result);
        $this->assertSame(['FR', 'FR'], $this->countries());
    }

    public function testARowWithoutALogLineAndARowWithACountryStayAsTheyAre(): void
    {
        Hits::add($this->hit('a', '10:00:00.000', country: 'FR'));
        Hits::add($this->hit('b', '11:00:00.000'));

        $result = AccessLog::fill([$this->log([$this->line('198.51.0.0', '10:00:00')])]);

        $this->assertSame(['missing' => 1, 'filled' => 0], $result);
        $this->assertSame(['FR', null], $this->countries());
    }

    /**
     * A stored request on 15 October 2026 at $time (local time with
     * milliseconds, or a full timestamp).
     *
     * @return Hit
     */
    private function hit(string $address, string $time, string $uri = '/photos', string $agent = self::SAFARI, ?string $net = null, ?string $country = null): array
    {
        return [
            'time' => str_contains($time, 'T') ? $time : "2026-10-15T$time+02:00",
            'ip_hash' => "ip-$address",
            'net_hash' => 'net-' . ($net ?? $address),
            'method' => 'GET',
            'uri' => $uri,
            'protocol' => 'HTTP/2.0',
            'page' => 'photos',
            'status' => 200,
            'session' => false,
            'headers' => $agent === '' ? [] : ['user-agent' => $agent],
            'country' => $country,
        ];
    }

    /** A line of the host's access log on 15 October 2026 at $time (local time). */
    private function line(string $network, string $time, string $uri = '/photos', string $method = 'GET', int $status = 200, string $agent = self::SAFARI): string
    {
        return "$network - - [15/Oct/2026:$time +0200] \"$method $uri HTTP/1.1\" $status 8523 \"-\" \"$agent\" \"Traffic IN:1027 OUT:13259\" \"ReqTime:0 sec\"";
    }

    /**
     * A gzip-compressed log file with $lines.
     *
     * @param list<string> $lines
     */
    private function log(array $lines): string
    {
        $file = $this->root . '/access_log_' . bin2hex(random_bytes(4)) . '.gz';
        file_put_contents($file, gzencode(implode("\n", $lines) . "\n"));
        return $file;
    }

    /** @return list<?string> */
    private function countries(): array
    {
        return array_column(Hits::rows('2026-10'), 'country');
    }
}
