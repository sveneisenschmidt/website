<?php

namespace Website\Analytics;

use RuntimeException;

/**
 * The country of an IP address, from the free database "IP to Country Lite"
 * of DB-IP (db-ip.com, CC BY 4.0: the Panel view that shows the countries
 * links to DB-IP). bin/countries.php downloads the database and writes it
 * with build() into the file ranges.bin in the Kirby root "countries": the
 * folder countries/ next to www/. The deploy builds it into every release;
 * locally `make countries` builds it. Without the file every address has
 * no country.
 *
 * ranges.bin holds two sorted lists of ranges: the IPv4 ranges with the
 * first address in 4 bytes, then the IPv6 ranges with 16 bytes, each with
 * the country code in 2 bytes. A range lasts until the next one starts.
 * Two zero bytes mark a range without a country. The header holds the
 * number of ranges of each list. of() finds a range by binary search, so a
 * lookup reads about 20 records and never the whole file.
 */
final class Country
{
    private const NONE = "\0\0";

    /** @var array<string, array{0: resource, 1: int, 2: int}> The open file per path: handle, IPv4 count, IPv6 count. */
    private static array $files = [];

    /** The path of the database, or null without the Kirby root "countries". */
    public static function file(): ?string
    {
        $root = kirby()->root('countries');
        return $root === null ? null : "$root/ranges.bin";
    }

    /**
     * The ISO 3166 code of the country of $ip, such as "DE", or null when
     * the database has no country for it or does not exist.
     */
    public static function of(string $ip): ?string
    {
        $packed = self::pack($ip);
        $db = self::open();
        if ($packed === null || $db === null) {
            return null;
        }
        $code = self::read($db, $packed, self::find($db, $packed));
        return $code === self::NONE ? null : $code;
    }

    /**
     * The country with the most addresses from the IPv4 address $first to
     * $last, both included, or null. The host's access log shortens an IPv4
     * address to its first two numbers (203.0.0.0): AccessLog looks up the
     * country of such a network with this method.
     */
    public static function mostIn(string $first, string $last): ?string
    {
        $from = self::pack($first);
        $to = self::pack($last);
        $db = self::open();
        if ($from === null || $to === null || strlen($from) !== 4 || strlen($to) !== 4 || $db === null) {
            return null;
        }
        $low = (int) unpack('N', $from)[1];
        $high = (int) unpack('N', $to)[1];
        $sizes = [];
        for ($i = max(0, self::find($db, $from)); $i < $db[1]; $i++) {
            $start = (int) unpack('N', self::start($db, $from, $i))[1];
            if ($start > $high) {
                break;
            }
            $next = $i + 1 < $db[1] ? (int) unpack('N', self::start($db, $from, $i + 1))[1] : $high + 1;
            $code = self::read($db, $from, $i);
            $sizes[$code] = ($sizes[$code] ?? 0) + min($next, $high + 1) - max($start, $low);
        }
        unset($sizes[self::NONE]);
        arsort($sizes);
        $code = array_key_first($sizes);
        return $code === null ? null : (string) $code;
    }

    /**
     * Writes the database of the CSV file $csv ("first,last,code" per line,
     * the CSV format of DB-IP, also gzip-compressed) into $file. Ranges next
     * to each other with the same country become one. A gap between two
     * ranges gets a range without a country, and so does the code ZZ
     * (DB-IP's code for reserved addresses).
     */
    public static function build(string $csv, string $file): void
    {
        $in = fopen((str_ends_with($csv, '.gz') ? 'compress.zlib://' : '') . $csv, 'r');
        if ($in === false) {
            throw new RuntimeException("Could not read $csv");
        }
        // Per address size: the records, their number, the code of the last
        // record and the address after the last range.
        $lists = [4 => self::list(), 16 => self::list()];
        while (($row = fgetcsv($in, escape: '')) !== false) {
            $first = self::pack((string) ($row[0] ?? ''));
            $last = self::pack((string) ($row[1] ?? ''));
            $code = strtoupper((string) ($row[2] ?? ''));
            if ($first === null || $last === null || strlen($first) !== strlen($last)) {
                continue;
            }
            $list = &$lists[strlen($first)];
            if ($list['end'] !== null && strcmp($first, $list['end']) > 0) {
                self::push($list, $list['end'], self::NONE);
            }
            self::push($list, $first, preg_match('/^[A-Z]{2}$/', $code) === 1 && $code !== 'ZZ' ? $code : self::NONE);
            $list['end'] = $last === str_repeat("\xff", strlen($last)) ? null : self::add($last, 1);
            unset($list);
        }
        fclose($in);
        foreach ([4, 16] as $size) {
            if ($lists[$size]['end'] !== null) {
                self::push($lists[$size], $lists[$size]['end'], self::NONE);
            }
        }
        $data = pack('NN', $lists[4]['count'], $lists[16]['count']) . $lists[4]['data'] . $lists[16]['data'];
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0755, true) || file_put_contents($tmp, $data) !== strlen($data) || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException("Could not write $file");
        }
    }

    /**
     * An empty list of ranges for build().
     *
     * @return array{data: string, count: int, code: ?string, end: ?string}
     */
    private static function list(): array
    {
        return ['data' => '', 'count' => 0, 'code' => null, 'end' => null];
    }

    /**
     * Adds a range that starts at $start, unless the last range has the
     * same country: then the last range goes on.
     *
     * @param array{data: string, count: int, code: ?string, end: ?string} $list
     */
    private static function push(array &$list, string $start, string $code): void
    {
        if ($list['code'] === $code) {
            return;
        }
        $list['data'] .= $start . $code;
        $list['count']++;
        $list['code'] = $code;
    }

    /**
     * The open database, or null when the file does not exist.
     *
     * @return array{0: resource, 1: int, 2: int}|null
     */
    private static function open(): ?array
    {
        $file = self::file();
        if ($file === null) {
            return null;
        }
        if (!isset(self::$files[$file])) {
            $handle = is_file($file) ? fopen($file, 'rb') : false;
            if ($handle === false) {
                return null;
            }
            $counts = unpack('Nv4/Nv6', (string) fread($handle, 8));
            if ($counts === false) {
                return null;
            }
            self::$files[$file] = [$handle, (int) $counts['v4'], (int) $counts['v6']];
        }
        return self::$files[$file];
    }

    /**
     * The index of the range that holds $packed, or -1 when $packed lies
     * before the first range.
     *
     * @param array{0: resource, 1: int, 2: int} $db
     */
    private static function find(array $db, string $packed): int
    {
        $low = 0;
        $high = (strlen($packed) === 4 ? $db[1] : $db[2]) - 1;
        $found = -1;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            if (strcmp(self::start($db, $packed, $middle), $packed) <= 0) {
                $found = $middle;
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }
        return $found;
    }

    /**
     * The first address of range $i in the list of the address family of
     * $packed.
     *
     * @param array{0: resource, 1: int, 2: int} $db
     */
    private static function start(array $db, string $packed, int $i): string
    {
        $size = strlen($packed);
        fseek($db[0], self::offset($db, $size, $i));
        return (string) fread($db[0], $size);
    }

    /**
     * The country code of range $i, or NONE when $i is -1.
     *
     * @param array{0: resource, 1: int, 2: int} $db
     */
    private static function read(array $db, string $packed, int $i): string
    {
        if ($i < 0) {
            return self::NONE;
        }
        $size = strlen($packed);
        fseek($db[0], self::offset($db, $size, $i) + $size);
        return (string) fread($db[0], 2);
    }

    /** @param array{0: resource, 1: int, 2: int} $db */
    private static function offset(array $db, int $size, int $i): int
    {
        return 8 + ($size === 4 ? $i * 6 : $db[1] * 6 + $i * 18);
    }

    /**
     * The binary form of a valid address. An IPv4 address that an IPv6
     * server reports as ::ffff:203.0.113.7 counts as the IPv4 address.
     */
    private static function pack(string $ip): ?string
    {
        $packed = filter_var($ip, FILTER_VALIDATE_IP) === false ? false : inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        return str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff") ? substr($packed, 12) : $packed;
    }

    /** $packed plus $n, as a binary address of the same length. */
    private static function add(string $packed, int $n): string
    {
        $bytes = array_values((array) unpack('C*', $packed));
        for ($i = count($bytes) - 1; $i >= 0 && $n > 0; $i--) {
            $sum = $bytes[$i] + $n;
            $bytes[$i] = $sum & 0xff;
            $n = $sum >> 8;
        }
        return pack('C*', ...$bytes);
    }
}
