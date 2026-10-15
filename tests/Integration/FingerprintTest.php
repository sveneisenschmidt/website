<?php

namespace Website\Tests\Integration;

use Website\Analytics\Fingerprint;

final class FingerprintTest extends IntegrationTestCase
{
    public function testTheSameAddressGivesTheSameFingerprintInEveryNotation(): void
    {
        $this->assertSame(Fingerprint::ip('2001:db8:1:2::abcd'), Fingerprint::ip('2001:DB8:1:2:0:0:0:ABCD'));
        $this->assertNotSame(Fingerprint::ip('203.0.113.7'), Fingerprint::ip('203.0.113.8'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', Fingerprint::ip('203.0.113.7'));
    }

    public function testAddressesInOneNetworkShareTheNetworkFingerprint(): void
    {
        $this->assertSame(Fingerprint::net('203.0.113.7'), Fingerprint::net('203.0.113.200'));
        $this->assertNotSame(Fingerprint::net('203.0.113.7'), Fingerprint::net('203.0.114.7'));
        $this->assertSame(Fingerprint::net('2001:db8:1:2::1'), Fingerprint::net('2001:db8:1:2:ffff::9'));
        $this->assertSame('203.0.113.0/24', Fingerprint::network('203.0.113.7'));
        $this->assertSame('2001:db8:1:2::/64', Fingerprint::network('2001:db8:1:2:aaaa::1'));
        $this->assertNull(Fingerprint::network('not an address'));
    }

    public function testTheKeyIsInStorageAndHoldsNoAddress(): void
    {
        $fingerprint = Fingerprint::ip('198.51.100.77');

        $key = (string) file_get_contents($this->root . '/storage/analytics.key');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
        $this->assertSame([$this->root . '/storage/analytics.key'], glob($this->root . '/storage/*'));
        $this->assertSame($fingerprint, Fingerprint::ip('198.51.100.77'), 'the second call reads the same key');
    }

    public function testOwnAddressesComeFromThePanelField(): void
    {
        $this->kirby->impersonate('kirby', fn() => $this->kirby->site()->update([
            'ownIps' => "198.51.100.7\n 2001:db8:1:2::/64 \n203.0.113.9/24\nnot an address\n10.0.0.0/16\n2001:db8::/48",
        ]));

        $this->assertSame([
            'ips' => [Fingerprint::ip('198.51.100.7')],
            'nets' => [Fingerprint::net('2001:db8:1:2::1'), Fingerprint::net('203.0.113.1')],
        ], Fingerprint::own());
    }
}
