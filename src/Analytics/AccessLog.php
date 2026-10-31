<?php

namespace Website\Analytics;

use DateTimeImmutable;

/**
 * Fills the country of stored requests that have none from the access log
 * of the host: the requests from before Counter stored the country, and
 * requests whose address had no country. The log shortens
 * every IPv4 address to its first two numbers (203.0.0.0). A row of Hits
 * holds no address, so AccessLog finds its log line by second, method,
 * URI, status and user agent, and takes the country with the most
 * addresses in that network (Country::mostIn()). One address can stand in
 * a log line of the second before or after.
 *
 * Rows with the same address fingerprint come from one address, and rows
 * with the same network fingerprint from one /24 network: such rows share
 * a network of the log. A row whose line matches several networks gets the
 * one network that the other rows of its address or /24 network leave. A
 * row of an address that has a country from Counter gets that country.
 * A row that still matches several networks gets a country only when all
 * of them lie in the same country.
 */
final class AccessLog
{
    /**
     * Fills the rows without a country in every month of Hits from the log
     * files in $files (gzip-compressed or plain, in the combined log format).
     * Returns the number of rows without a country before and the number
     * filled.
     *
     * @param list<string> $files
     * @return array{missing: int, filled: int}
     */
    public static function fill(array $files): array
    {
        $lines = self::lines($files);
        $missing = 0;
        $filled = 0;
        foreach (Hits::months() as $month) {
            $rows = Hits::rows($month);
            $countries = self::countries($rows, $lines);
            $missing += count(array_filter($rows, fn(array $row) => $row['country'] === null));
            $filled += count($countries);
            if ($countries !== []) {
                Hits::setCountries($month, $countries);
            }
        }
        return ['missing' => $missing, 'filled' => $filled];
    }

    /**
     * The networks of the log lines, per second, method, URI, status and
     * user agent. The URI loses its click ids, as Counter stores it.
     *
     * @param list<string> $files
     * @return array<string, array<string, true>>
     */
    public static function lines(array $files): array
    {
        $lines = [];
        foreach ($files as $file) {
            $handle = fopen((str_ends_with($file, '.gz') ? 'compress.zlib://' : '') . $file, 'r');
            if ($handle === false) {
                continue;
            }
            while (($line = fgets($handle)) !== false) {
                if (preg_match('~^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+) (\S+) [^"]*" (\d{3}) \S+ "(?:[^"\\\\]|\\\\.)*" "((?:[^"\\\\]|\\\\.)*)"~', $line, $m) !== 1) {
                    continue;
                }
                $time = DateTimeImmutable::createFromFormat('d/M/Y:H:i:s O', $m[2]);
                if ($time === false) {
                    continue;
                }
                $agent = $m[6] === '-' ? '' : stripcslashes($m[6]);
                $lines[self::key($time->getTimestamp(), $m[3], Counter::withoutClickIds($m[4]), (int) $m[5], $agent)][$m[1]] = true;
            }
            fclose($handle);
        }
        return $lines;
    }

    /**
     * The country per row id for the rows of one month without a country.
     *
     * @param list<array{id: int, time: string, ip_hash: string, net_hash: string, method: string, uri: string, status: int, agent: string, country: ?string}> $rows
     * @param array<string, array<string, true>> $lines
     * @return array<int, string>
     */
    public static function countries(array $rows, array $lines): array
    {
        $known = [];
        $networks = [];
        foreach ($rows as $row) {
            if ($row['country'] !== null) {
                $known[$row['ip_hash']] = $row['country'];
                continue;
            }
            $second = (new DateTimeImmutable($row['time']))->getTimestamp();
            $found = [];
            foreach ([$second, $second - 1, $second + 1] as $time) {
                $found = $lines[self::key($time, $row['method'], $row['uri'], $row['status'], $row['agent'])] ?? [];
                if ($found !== []) {
                    break;
                }
            }
            $networks[$row['id']] = array_keys($found);
        }
        foreach (['ip_hash', 'net_hash'] as $column) {
            $groups = [];
            foreach ($rows as $row) {
                if (isset($networks[$row['id']])) {
                    $groups[$row[$column]][] = $row['id'];
                }
            }
            foreach ($groups as $ids) {
                $shared = null;
                foreach ($ids as $id) {
                    if ($networks[$id] !== []) {
                        $shared = $shared === null ? $networks[$id] : (array_values(array_intersect($shared, $networks[$id])) ?: $shared);
                    }
                }
                if ($shared !== null && count($shared) === 1) {
                    foreach ($ids as $id) {
                        $networks[$id] = $shared;
                    }
                }
            }
        }
        $countries = [];
        foreach ($rows as $row) {
            if (!isset($networks[$row['id']])) {
                continue;
            }
            $country = $known[$row['ip_hash']] ?? null;
            if ($country === null) {
                $found = array_values(array_unique(array_map(self::country(...), $networks[$row['id']])));
                $country = count($found) === 1 ? $found[0] : null;
            }
            if ($country !== null) {
                $countries[$row['id']] = $country;
            }
        }
        return $countries;
    }

    /**
     * The country of a network of the log: an IPv4 address with its last
     * two numbers 0 stands for those 65,536 addresses. Any other address
     * stands for itself.
     */
    private static function country(string $network): ?string
    {
        if (preg_match('/^(\d+\.\d+)\.0\.0$/', $network, $m) === 1) {
            return Country::mostIn("$m[1].0.0", "$m[1].255.255");
        }
        return Country::of($network);
    }

    private static function key(int $second, string $method, string $uri, int $status, string $agent): string
    {
        return "$second $method $status $uri $agent";
    }
}
