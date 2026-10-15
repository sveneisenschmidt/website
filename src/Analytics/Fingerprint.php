<?php

namespace Website\Analytics;

use Website\Blog\Lock;

/**
 * Turns an IP address into a fingerprint: an HMAC (a hash with a secret
 * key) that stays the same for the same address on every day. The key is in
 * storage/analytics.key next to www/. It is not in the repo and not in a
 * backup, so a copy of the statistics without the key reveals no address.
 * Whoever holds the key can test every address. The access log of the
 * hosting company already shows every address to that person.
 *
 * The network fingerprint covers the first three numbers of an IPv4 address
 * (/24) or the first half of an IPv6 address (/64). A phone changes its
 * IPv6 address often, but mostly keeps its /64 network.
 */
final class Fingerprint
{
    /** @var array<string, string> The key per key file. */
    private static array $keys = [];

    public static function ip(string $ip): string
    {
        $packed = self::pack($ip);
        return self::hash($packed === null ? $ip : (string) inet_ntop($packed));
    }

    public static function net(string $ip): string
    {
        return self::hash(self::network($ip) ?? $ip);
    }

    /** The network of an address, such as "203.0.113.0/24" or "2001:db8:1:2::/64", or null when $ip is no address. */
    public static function network(string $ip): ?string
    {
        $packed = self::pack($ip);
        if ($packed === null) {
            return null;
        }
        return strlen($packed) === 4
            ? inet_ntop(substr($packed, 0, 3) . "\0") . '/24'
            : inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    /**
     * The fingerprints of the Panel field "Eigene IP-Adressen" (Website ›
     * Besucher). Each line holds one address, or one network with /24 for
     * IPv4 or /64 for IPv6. Totals ignores other lines.
     *
     * @return array{ips: list<string>, nets: list<string>}
     */
    public static function own(): array
    {
        $own = ['ips' => [], 'nets' => []];
        foreach (preg_split('/\R/', (string) kirby()->site()->ownIps()->value()) ?: [] as $line) {
            $line = trim($line);
            if (preg_match('#^(.+)/(\d+)$#', $line, $m)) {
                $network = self::network($m[1]);
                if ($network !== null && str_ends_with($network, '/' . $m[2])) {
                    $own['nets'][] = self::hash($network);
                }
            } elseif (self::pack($line) !== null) {
                $own['ips'][] = self::ip($line);
            }
        }
        return $own;
    }

    /**
     * The binary form of a valid IPv4 or IPv6 address, else null. A server
     * that listens on IPv6 can report an IPv4 client as ::ffff:203.0.113.7.
     * That form counts as the IPv4 address, so it gets the same fingerprint
     * and the /24 network.
     */
    private static function pack(string $ip): ?string
    {
        $packed = filter_var($ip, FILTER_VALIDATE_IP) === false ? false : inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        return str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff") ? substr($packed, 12) : $packed;
    }

    private static function hash(string $value): string
    {
        return substr(hash_hmac('sha256', $value, self::key()), 0, 32);
    }

    /**
     * The key, read once per request and key file. The first request
     * creates it under the lock storage/.analytics.key.lock, so parallel
     * first requests all read the same key. rename() puts the complete file
     * in place in one step.
     */
    private static function key(): string
    {
        $dir = kirby()->root('storage');
        $file = "$dir/analytics.key";
        if (!isset(self::$keys[$file])) {
            if (!is_file($file)) {
                Lock::run("$dir/.analytics.key.lock", LOCK_EX, function () use ($file) {
                    if (!is_file($file)) {
                        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
                        file_put_contents($tmp, bin2hex(random_bytes(32)));
                        rename($tmp, $file);
                    }
                });
            }
            self::$keys[$file] = (string) file_get_contents($file);
        }
        return self::$keys[$file];
    }
}
