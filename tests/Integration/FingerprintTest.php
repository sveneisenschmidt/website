<?php

namespace Website\Tests\Integration;

use LogicException;
use RuntimeException;
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

    public function testAnIpv4AddressInIpv6FormCountsAsTheIpv4Address(): void
    {
        $this->assertSame(Fingerprint::ip('203.0.113.7'), Fingerprint::ip('::ffff:203.0.113.7'));
        $this->assertSame(Fingerprint::net('203.0.113.7'), Fingerprint::net('::ffff:203.0.113.99'));
        $this->assertSame('203.0.113.0/24', Fingerprint::network('::ffff:203.0.113.7'));
    }

    public function testTheKeyIsInStorageAndHoldsNoAddress(): void
    {
        $fingerprint = Fingerprint::ip('198.51.100.77');

        $key = (string) file_get_contents($this->root . '/storage/analytics.key');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
        $this->assertSame([$this->root . '/storage/analytics.key'], glob($this->root . '/storage/*'));
        $this->assertSame($fingerprint, Fingerprint::ip('198.51.100.77'), 'the second call reads the same key');
    }

    public function testOnlyTheAccountUserReadsTheKey(): void
    {
        Fingerprint::ip('198.51.100.77');

        $this->assertSame(0600, fileperms($this->root . '/storage/analytics.key') & 0777);
    }

    /** A write that failed on a full disk can leave an empty key file. Such a file gets a new key. */
    public function testAnEmptyKeyFileGetsANewKey(): void
    {
        mkdir($this->root . '/storage');
        touch($this->root . '/storage/analytics.key');

        Fingerprint::ip('198.51.100.77');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) file_get_contents($this->root . '/storage/analytics.key'));
    }

    public function testAKeyThatCannotBeWrittenMakesNoFingerprint(): void
    {
        mkdir($this->root . '/storage');
        touch($this->root . '/storage/.analytics.key.lock');
        chmod($this->root . '/storage', 0500);
        try {
            Fingerprint::ip('198.51.100.77');
            $this->fail('A fingerprint was made without a key.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Cannot write the key', $e->getMessage());
        } finally {
            chmod($this->root . '/storage', 0700);
        }
        $this->assertSame(['.analytics.key.lock'], array_values(array_diff(scandir($this->root . '/storage'), ['.', '..'])));
    }

    public function testWithoutTheStorageRootNoFingerprintIsMade(): void
    {
        $this->kirby->clone(['roots' => ['storage' => null]]);

        $this->expectException(LogicException::class);
        Fingerprint::ip('203.0.113.7');
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
