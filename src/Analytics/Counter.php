<?php

namespace Website\Analytics;

use DateTimeImmutable;
use Kirby\Cms\Page;
use Kirby\Http\Response;
use Throwable;

/**
 * Records every request that Kirby answers, as raw as the privacy rules
 * allow, without cookies, scripts or external services: pages, feeds,
 * redirects and paths without a page. Bots are recorded too. Kind sorts the
 * requests when the Panel or About reads them. plugins/analytics calls
 * route() from Kirby's hook route:after. That hook runs before
 * Page::render() reads the page cache, so requests for cached pages are
 * recorded too. Hits stores the requests.
 */
final class Counter
{
    /** Headers that are never stored: login secrets and the IP addresses that proxies add. */
    private const PRIVATE_HEADERS = ['cookie', 'authorization', 'proxy-authorization', 'x-forwarded-for', 'x-real-ip', 'forwarded', 'x-client-ip', 'client-ip', 'true-client-ip', 'cf-connecting-ip', 'x-cluster-client-ip', 'fastly-client-ip'];

    /**
     * Handler for the hook route:after. Kirby replaces the route result with
     * every return value of this hook other than null, so the method returns
     * nothing.
     */
    public static function route(string $path, string $method, mixed $result, bool $final): void
    {
        if (!$final || !self::isRecorded($path)) {
            return;
        }
        try {
            self::record($path, $method, $result);
        } catch (Throwable $e) {
            // An error while recording must not fail the request.
            error_log('blog analytics: ' . $e->getMessage());
        }
    }

    /** False for the Panel, its API and the thumbnails in media/: these requests come from the editor or from Kirby. */
    public static function isRecorded(string $path): bool
    {
        $kirby = kirby();
        $skip = [$kirby->option('panel.slug', 'panel'), $kirby->option('api.slug', 'api'), 'media'];
        return !in_array(explode('/', $path)[0], $skip, true);
    }

    /**
     * Stores one request in Hits: the time in ISO 8601 with milliseconds and
     * offset, as Website\Blog\Jsonl writes it, the fingerprints of the IP
     * address and its network, the URI with its query, the protocol and the
     * headers.
     * `session` marks a browser with a Panel session. The method reads
     * $_COOKIE directly: Kirby's user() or Cookie::get() would mark the
     * response as private, and Kirby would not cache the page.
     */
    public static function record(string $path, string $method, mixed $result): void
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        Hits::add([
            'time' => (new DateTimeImmutable())->format('Y-m-d\TH:i:s.vP'),
            'ip_hash' => Fingerprint::ip($ip),
            'net_hash' => Fingerprint::net($ip),
            'method' => $method,
            'uri' => mb_scrub((string) ($_SERVER['REQUEST_URI'] ?? '/' . $path), 'UTF-8'),
            'protocol' => (string) ($_SERVER['SERVER_PROTOCOL'] ?? ''),
            'page' => $result instanceof Page ? self::pageId($result) : null,
            'status' => self::status($result),
            'session' => isset($_COOKIE['kirby_session']),
            'headers' => self::headers($ip),
        ]);
    }

    /**
     * Every request header as name => value, the names in lower case,
     * without PRIVATE_HEADERS and without a header whose value contains the
     * IP address. mb_scrub() replaces broken bytes: the Panel sends values as
     * JSON, and JSON needs valid UTF-8.
     *
     * @return array<string, string>
     */
    public static function headers(string $ip): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            $key = (string) $key;
            $header = str_starts_with($key, 'HTTP_') || in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true);
            if (!$header || !is_string($value)) {
                continue;
            }
            $name = strtolower(str_replace('_', '-', str_starts_with($key, 'HTTP_') ? substr($key, 5) : $key));
            if (in_array($name, self::PRIVATE_HEADERS, true) || ($ip !== '' && str_contains($value, $ip))) {
                continue;
            }
            $headers[$name] = mb_scrub($value, 'UTF-8');
        }
        ksort($headers);
        return $headers;
    }

    /** The HTTP status of the answer. Kirby answers a route without a result and the error page with 404. */
    public static function status(mixed $result): int
    {
        return match (true) {
            $result instanceof Response => $result->code(),
            $result instanceof Page => $result->isErrorPage() ? 404 : 200,
            empty($result) => 404,
            default => 200,
        };
    }

    /**
     * The id of the page to count, or null. A draft does not count, because
     * only a preview link opens it. The error page does not count, because
     * the requested page does not exist.
     */
    public static function pageId(Page $page): ?string
    {
        if ($page->isDraft() || $page->isErrorPage()) {
            return null;
        }
        return $page->id();
    }
}
