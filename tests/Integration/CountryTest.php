<?php

namespace Website\Tests\Integration;

use Website\Analytics\Country;
use Website\Tests\Support\TestSite;

final class CountryTest extends IntegrationTestCase
{
    public function testAnAddressGetsTheCountryOfItsRange(): void
    {
        TestSite::countries($this->root);

        $this->assertSame('DE', Country::of('198.51.100.7'));
        $this->assertSame('DE', Country::of('198.51.100.200'));
        $this->assertSame('DE', Country::of('::ffff:198.51.100.7'));
        $this->assertSame('AT', Country::of('203.0.0.0'));
        $this->assertSame('FR', Country::of('203.0.113.255'));
        $this->assertSame('NL', Country::of('2001:db8:1:2::abcd'));
    }

    public function testAnAddressOutsideTheRangesOrWithZzHasNoCountry(): void
    {
        TestSite::countries($this->root);

        foreach (['192.0.2.1', '10.0.0.1', '198.51.101.0', '203.0.114.0', '2001:db9::1', '::1', 'unknown', ''] as $ip) {
            $this->assertNull(Country::of($ip), $ip);
        }
    }

    public function testWithoutTheDatabaseNoAddressHasACountry(): void
    {
        $this->assertNull(Country::of('198.51.100.7'));
        $this->assertNull(Country::mostIn('198.51.0.0', '198.51.255.255'));
    }

    public function testANetworkGetsTheCountryWithTheMostAddresses(): void
    {
        TestSite::countries($this->root);

        $this->assertSame('AT', Country::mostIn('203.0.0.0', '203.0.255.255'));
        $this->assertSame('FR', Country::mostIn('203.0.113.0', '203.0.255.255'));
        $this->assertSame('DE', Country::mostIn('198.51.0.0', '198.51.255.255'));
        $this->assertNull(Country::mostIn('192.0.0.0', '192.0.255.255'));
        $this->assertNull(Country::mostIn('2001:db8::', '2001:db8::ffff'));
    }

    public function testARangeUpToTheLastAddressHasNoGapAfterIt(): void
    {
        file_put_contents($this->root . '/top.csv', "203.0.113.0,203.0.113.255,FR\n224.0.0.0,255.255.255.255,ZZ\nffff::,ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff,CH\n");
        Country::build($this->root . '/top.csv', $this->root . '/countries/ranges.bin');

        $this->assertNull(Country::of('255.255.255.255'));
        $this->assertSame('CH', Country::of('ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'));
        // IPv4: FR, then none up to the last address. IPv6: CH.
        $this->assertSame(8 + 2 * 6 + 1 * 18, filesize($this->root . '/countries/ranges.bin'));
    }

    public function testRangesOfOneCountryNextToEachOtherBecomeOne(): void
    {
        TestSite::countries($this->root);

        // IPv4: from 192.0.2.0 none (ZZ and the gap after it), DE (two
        // ranges), none, AT, FR, none. IPv6: NL, none.
        $this->assertSame(8 + 6 * 6 + 2 * 18, filesize($this->root . '/countries/ranges.bin'));
    }
}
