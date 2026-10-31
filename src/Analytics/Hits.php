<?php

namespace Website\Analytics;

use Kirby\Filesystem\Dir;
use PDO;
use Pdo\Sqlite;
use Throwable;

/**
 * Stores every request that Counter records, in one SQLite database per
 * month: content/.analytics/<year>-<month>.sqlite. The files are in
 * content/, so every backup has them and git does not. bin/backup.sh links
 * an unchanged file to the previous snapshot, so only the database of the
 * current month takes new space.
 *
 * @phpstan-import-type Day from Months
 * @phpstan-type Hit array{time: string, ip_hash: string, net_hash: string, method: string, uri: string, protocol: string, page: ?string, status: int, session: bool, headers: array<string, string>, country: ?string}
 */
final class Hits
{
    /**
     * A page view of a person: a request of the group Kind::BROWSER opened
     * a public page (Sec-Fetch-Dest "document"), and the page loaded. A
     * prefetch request does not count, because the visitor has not opened
     * the page yet. A request with a Panel session or from an address in
     * "Eigene IP-Adressen" does not count either. connect() adds the rules
     * of Kind and Fingerprint as the SQL functions kind() and own(), so the
     * rules stay in one place.
     */
    private const VIEW = "method = 'GET' AND status = 200 AND page IS NOT NULL AND session = 0 AND fetch_dest = 'document' AND purpose IS NULL AND kind(agent, fetch_dest IS NOT NULL, COALESCE(language, '') <> '') = 'browser' AND own(ip_hash, net_hash) = 0";

    /**
     * One row per request. `time` is ISO 8601 with milliseconds and offset
     * (2026-10-25T02:30:00.000+01:00), so the hour that the change to winter
     * time repeats stays apart, and its first 10 characters are the local
     * date. The stored columns keep the request as it came,
     * with every header in `headers` as a JSON object. No column holds an IP
     * address: `ip_hash` and `net_hash` hold the fingerprints of the address
     * and its network (Fingerprint). `country` holds the country of the
     * address (Country), or null when it is unknown. The generated columns
     * read single headers, and SQLite computes them on every read. ALTER TABLE can add a
     * further generated column later, and it applies to old rows too.
     */
    private const SCHEMA = <<<'SQL'
        CREATE TABLE IF NOT EXISTS hits (
            time TEXT NOT NULL,
            ip_hash TEXT NOT NULL,
            net_hash TEXT NOT NULL,
            method TEXT NOT NULL,
            uri TEXT NOT NULL,
            protocol TEXT NOT NULL,
            page TEXT,
            status INTEGER NOT NULL,
            session INTEGER NOT NULL,
            headers TEXT NOT NULL,
            country TEXT,
            agent TEXT GENERATED ALWAYS AS (COALESCE(json_extract(headers, '$."user-agent"'), '')) VIRTUAL,
            visitor TEXT GENERATED ALWAYS AS (ip_hash || ' ' || agent) VIRTUAL,
            referer TEXT GENERATED ALWAYS AS (json_extract(headers, '$.referer')) VIRTUAL,
            fetch_dest TEXT GENERATED ALWAYS AS (json_extract(headers, '$."sec-fetch-dest"')) VIRTUAL,
            language TEXT GENERATED ALWAYS AS (json_extract(headers, '$."accept-language"')) VIRTUAL,
            purpose TEXT GENERATED ALWAYS AS (COALESCE(json_extract(headers, '$."sec-purpose"'), json_extract(headers, '$.purpose'))) VIRTUAL
        );
        CREATE INDEX IF NOT EXISTS hits_time ON hits (time);
        SQL;

    /** The stored columns that later versions added, with their definition. */
    private const ADDED = ['country' => 'country TEXT'];

    /**
     * The folder of the visitor statistics, also of the older totals in
     * Months.
     */
    public static function dir(): string
    {
        return kirby()->root('content') . '/.analytics';
    }

    /** @param Hit $hit */
    public static function add(array $hit): void
    {
        self::connect(self::dir() . '/' . substr($hit['time'], 0, 7) . '.sqlite')->prepare(
            'INSERT INTO hits (time, ip_hash, net_hash, method, uri, protocol, page, status, session, headers, country)
             VALUES (:time, :ip_hash, :net_hash, :method, :uri, :protocol, :page, :status, :session, :headers, :country)',
        )->execute([
            ...$hit,
            'session' => (int) $hit['session'],
            'headers' => json_encode((object) $hit['headers'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * The months with a database, oldest first.
     *
     * @return list<string>
     */
    public static function months(): array
    {
        return array_map(fn(string $file) => basename($file, '.sqlite'), glob(self::dir() . '/*.sqlite') ?: []);
    }

    /**
     * The page views of persons from $from to $to, both dates included, in
     * the form of the days in Months. A visitor is one address with one
     * browser on one day. The origin of a visit comes from its first view
     * (Kind::origin()). A visit without a country counts under "". Without
     * $details, the days have no referrers and no countries: About does not
     * show them.
     *
     * @return array<string, Day>
     */
    public static function days(string $from, string $to, bool $details = true): array
    {
        $where = 'time >= :from AND time < :end AND ' . self::VIEW;
        $queries = [
            'days' => "SELECT substr(time, 1, 10) AS day, COUNT(DISTINCT visitor) AS visits, COUNT(*) AS views FROM hits WHERE $where GROUP BY day",
            'pages' => "SELECT substr(time, 1, 10) AS day, page, COUNT(DISTINCT visitor) AS visits FROM hits WHERE $where GROUP BY day, page",
        ];
        if ($details) {
            $queries['countries'] = "SELECT substr(time, 1, 10) AS day, COALESCE(country, '') AS country, COUNT(DISTINCT visitor) AS visits FROM hits WHERE $where GROUP BY day, 2";
            $first = "SELECT MIN(rowid) FROM hits WHERE $where GROUP BY substr(time, 1, 10), visitor";
            $queries['origins'] = "SELECT substr(time, 1, 10) AS day, origin(referer, uri) AS origin, COUNT(*) AS visits FROM hits WHERE rowid IN ($first) GROUP BY day, origin HAVING origin IS NOT NULL";
        }
        $rows = self::select($queries, $from, $to);
        $days = [];
        foreach ($rows['days'] as $row) {
            $days[(string) $row['day']] = ['visits' => (int) $row['visits'], 'views' => (int) $row['views'], 'pages' => [], 'referrers' => [], 'countries' => []];
        }
        foreach ($rows['pages'] as $row) {
            $days[(string) $row['day']]['pages'][(string) $row['page']] = (int) $row['visits'];
        }
        foreach ($rows['origins'] ?? [] as $row) {
            $days[(string) $row['day']]['referrers'][(string) $row['origin']] = (int) $row['visits'];
        }
        foreach ($rows['countries'] ?? [] as $row) {
            $days[(string) $row['day']]['countries'][(string) $row['country']] = (int) $row['visits'];
        }
        ksort($days);
        return $days;
    }

    /**
     * The requests from $from to $to, both dates included, counted per user
     * agent and per set of headers, without the own addresses. Totals sorts
     * them with Kind.
     *
     * @return list<array{agent: string, fetchDest: bool, language: bool, hits: int}>
     */
    public static function agents(string $from, string $to): array
    {
        return array_map(fn(array $row) => [
            'agent' => (string) $row['agent'],
            'fetchDest' => (bool) $row['fetch_dest'],
            'language' => (bool) $row['language'],
            'hits' => (int) $row['hits'],
        ], self::select(['agents' => "SELECT agent, fetch_dest IS NOT NULL AS fetch_dest, COALESCE(language, '') <> '' AS language, COUNT(*) AS hits FROM hits WHERE time >= :from AND time < :end AND own(ip_hash, net_hash) = 0 GROUP BY 1, 2, 3"], $from, $to)['agents']);
    }

    /**
     * Every row of the database of $month ("2026-10") with the columns that
     * AccessLog compares, oldest first.
     *
     * @return list<array{id: int, time: string, ip_hash: string, net_hash: string, method: string, uri: string, status: int, agent: string, country: ?string}>
     */
    public static function rows(string $month): array
    {
        $statement = self::connect(self::dir() . "/$month.sqlite")->query('SELECT rowid AS id, time, ip_hash, net_hash, method, uri, status, agent, country FROM hits ORDER BY rowid');
        return array_map(fn(array $row) => [
            'id' => (int) $row['id'],
            'time' => (string) $row['time'],
            'ip_hash' => (string) $row['ip_hash'],
            'net_hash' => (string) $row['net_hash'],
            'method' => (string) $row['method'],
            'uri' => (string) $row['uri'],
            'status' => (int) $row['status'],
            'agent' => (string) $row['agent'],
            'country' => $row['country'] === null ? null : (string) $row['country'],
        ], $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Sets the country of the rows of $month in one transaction.
     *
     * @param array<int, string> $countries country code per row id
     */
    public static function setCountries(string $month, array $countries): void
    {
        $db = self::connect(self::dir() . "/$month.sqlite");
        $statement = $db->prepare('UPDATE hits SET country = :country WHERE rowid = :id');
        $db->beginTransaction();
        try {
            foreach ($countries as $id => $country) {
                $statement->execute(['id' => $id, 'country' => $country]);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Runs every query of $queries on the database of every month from
     * $from to $to, with one connection per month, and returns the rows per
     * query. A database that cannot be read is logged and skipped, so the
     * Panel view still opens.
     *
     * @param array<string, string> $queries
     * @return array<string, list<array<string, mixed>>>
     */
    private static function select(array $queries, string $from, string $to): array
    {
        $params = ['from' => $from, 'end' => date('Y-m-d', strtotime("$to +1 day"))];
        $rows = array_fill_keys(array_keys($queries), []);
        foreach (self::months() as $month) {
            if ($month < substr($from, 0, 7) || $month > substr($to, 0, 7)) {
                continue;
            }
            try {
                $db = self::connect(self::dir() . "/$month.sqlite");
                $found = [];
                foreach ($queries as $name => $sql) {
                    $statement = $db->prepare($sql);
                    $statement->execute($params);
                    /** @var list<array<string, mixed>> $list */
                    $list = $statement->fetchAll(PDO::FETCH_ASSOC);
                    $found[$name] = $list;
                }
                // A month adds its rows only when every query worked.
                foreach ($found as $name => $list) {
                    array_push($rows[$name], ...$list);
                }
            } catch (Throwable $e) {
                error_log('blog analytics: ' . $e->getMessage());
            }
        }
        return $rows;
    }

    /**
     * Opens the database in $file and creates it when it is missing.
     * Parallel requests wait up to 5 seconds for the lock of the file. A
     * database from before a column of ADDED gets the column (upgrade()).
     * origin() and own() depend on the site and its Panel fields, so they
     * are not deterministic for SQLite.
     */
    private static function connect(string $file): Sqlite
    {
        Dir::make(dirname($file));
        $db = new Sqlite('sqlite:' . $file, null, null, [PDO::ATTR_TIMEOUT => 5]);
        $db->exec(self::SCHEMA);
        self::upgrade($db);
        $db->createFunction('kind', fn(string $agent, int $fetchDest, int $language): string => Kind::of($agent, (bool) $fetchDest, (bool) $language), 3, Sqlite::DETERMINISTIC);
        $db->createFunction('origin', fn(?string $referer, string $uri): ?string => Kind::origin($referer, $uri), 2);
        $own = null;
        $db->createFunction('own', function (string $ipHash, string $netHash) use (&$own): int {
            $own ??= Fingerprint::own();
            return (int) (in_array($ipHash, $own['ips'], true) || in_array($netHash, $own['nets'], true));
        }, 2);
        return $db;
    }

    /**
     * Adds the columns of ADDED that the table lacks. Old rows get null.
     * BEGIN IMMEDIATE takes the write lock before the check, so of two
     * parallel requests only the first adds a column and the second sees it.
     */
    private static function upgrade(Sqlite $db): void
    {
        $missing = function () use ($db): array {
            $statement = $db->query('PRAGMA table_info(hits)');
            return array_diff_key(self::ADDED, array_flip($statement === false ? [] : $statement->fetchAll(PDO::FETCH_COLUMN, 1)));
        };
        if ($missing() === []) {
            return;
        }
        $db->exec('BEGIN IMMEDIATE');
        try {
            foreach ($missing() as $definition) {
                $db->exec("ALTER TABLE hits ADD COLUMN $definition");
            }
            $db->exec('COMMIT');
        } catch (Throwable $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }
    }
}
