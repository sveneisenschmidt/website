<?php

namespace Website\Likes;

use DateTimeImmutable;
use Kirby\Cms\Response;
use Kirby\Filesystem\Dir;
use PDO;
use Pdo\Sqlite;
use Throwable;
use Website\Analytics\Fingerprint;
use Website\Analytics\Kind;
use Website\Blog\Router;
use Website\Models\PostPage;

/**
 * The likes of the posts, in the SQLite database content/.likes.sqlite. The
 * file is in content/, so every backup has it and git does not.
 * plugins/likes registers the route, the snippet of the like button and the
 * Panel view "Likes". The button is a form that sends POST to
 * /YYYY-MM-DD-slug/like. It works without JavaScript and without a cookie.
 * A post counts one like per IP address. As in the visitor statistics, an
 * error with the file goes to the PHP error log and fails no request.
 *
 * @phpstan-type LikedPost array{title: string, url: ?string, likes: int}
 * @phpstan-type Like array{title: string, url: ?string, date: string}
 */
final class Likes
{
    /**
     * One row per like. `page` is the UUID of the post, so a like stays
     * after a change of slug or date. `ip_hash` is the fingerprint of the IP
     * address (Fingerprint). The likes from Pop, the counter before Kirby,
     * have no fingerprint. SQLite treats every NULL as a different value, so
     * UNIQUE does not stop them.
     */
    private const SCHEMA = <<<'SQL'
        CREATE TABLE IF NOT EXISTS likes (
            page TEXT NOT NULL,
            ip_hash TEXT,
            time TEXT NOT NULL,
            UNIQUE (page, ip_hash)
        );
        SQL;

    public static function file(): string
    {
        return kirby()->root('content') . '/.likes.sqlite';
    }

    /** The number of likes of the post, or null when the file cannot be read. */
    public static function count(PostPage $post): ?int
    {
        if (!is_file(self::file())) {
            return 0;
        }
        try {
            $query = self::connect(self::file())->prepare('SELECT COUNT(*) FROM likes WHERE page = :page');
            $query->execute(['page' => $post->uuid()->id()]);
            return (int) $query->fetchColumn();
        } catch (Throwable $e) {
            error_log('blog likes: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * The likes for the Panel view "Likes" (plugins/likes). It gives the
     * total, the likes of the last $days days, today included, the posts by
     * likes and every like, newest first. Of two posts with the same number
     * of likes, the one with the newer like comes first. A deleted post
     * keeps its likes and shows as "(gelöscht)", as in
     * Website\Analytics\Totals. A file that cannot be read gives an empty
     * summary, so the view still opens.
     *
     * @return array{total: int, month: int, posts: list<LikedPost>, likes: list<Like>}
     */
    public static function summary(int $days = 30): array
    {
        $summary = ['total' => 0, 'month' => 0, 'posts' => [], 'likes' => []];
        if (!is_file(self::file())) {
            return $summary;
        }
        try {
            $rows = self::connect(self::file())->query('SELECT page, time FROM likes ORDER BY time DESC, rowid DESC')->fetchAll(PDO::FETCH_NUM);
        } catch (Throwable $e) {
            error_log('blog likes: ' . $e->getMessage());
            return $summary;
        }
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $pages = [];
        $counts = [];
        foreach ($rows as [$uuid, $time]) {
            $pages[$uuid] ??= self::page((string) $uuid);
            $counts[$uuid] = ($counts[$uuid] ?? 0) + 1;
            $summary['likes'][] = [...$pages[$uuid], 'date' => substr((string) $time, 0, 10)];
            if (substr((string) $time, 0, 10) >= $from) {
                $summary['month']++;
            }
        }
        $summary['total'] = count($rows);
        // $counts has the posts in the order of their newest like. uasort()
        // is stable, so posts with the same number of likes keep that order.
        uasort($counts, fn(int $a, int $b) => $b <=> $a);
        foreach ($counts as $uuid => $likes) {
            $summary['posts'][] = [...$pages[$uuid], 'likes' => $likes];
        }
        return $summary;
    }

    /**
     * The title and URL of the post with the UUID $uuid.
     *
     * @return array{title: string, url: ?string}
     */
    private static function page(string $uuid): array
    {
        $page = kirby()->page('page://' . $uuid);
        return $page === null
            ? ['title' => "$uuid (gelöscht)", 'url' => null]
            : ['title' => $page->title()->value(), 'url' => $page->url()];
    }

    /**
     * The values of the like button (plugins/likes/snippets/like.php):
     * `likes` is the count, `liked` is true after a like of this IP address.
     * Only a request with ?liked checks the address: Kirby never caches such
     * a request, and the cached page must be the same for every visitor.
     * When count() cannot read the file, `likes` is null. The post then
     * stays out of the page cache, so the first request after a repair
     * shows the count again.
     *
     * @return array{likes: ?int, liked: bool}
     */
    public static function button(PostPage $post): array
    {
        $kirby = $post->kirby();
        $likes = self::count($post);
        if ($likes === null) {
            $kirby->response()->cache(false);
        }
        $liked = $kirby->request()->query()->get('liked') !== null && self::has($post, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        return ['likes' => $likes, 'liked' => $liked];
    }

    /** True when the IP address $ip has liked the post. */
    public static function has(PostPage $post, string $ip): bool
    {
        if (!is_file(self::file())) {
            return false;
        }
        try {
            $query = self::connect(self::file())->prepare('SELECT 1 FROM likes WHERE page = :page AND ip_hash = :ip_hash');
            $query->execute(['page' => $post->uuid()->id(), 'ip_hash' => Fingerprint::ip($ip)]);
            return $query->fetchColumn() !== false;
        } catch (Throwable $e) {
            error_log('blog likes: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * The answer to POST /YYYY-MM-DD-slug/like: it stores the like and leads
     * back to the post, or gives null for no published post. A request that
     * Kind does not count as a browser leads back without a like. A new like
     * removes the post from the page cache, so the next visitor sees the new
     * count. ?liked lets the post say "Liked" to this address (button()),
     * and #like scrolls to the form.
     */
    public static function route(string $date, string $slug): ?Response
    {
        $post = Router::post($date, $slug);
        if ($post === null || !$post->isListed()) {
            return null;
        }
        $agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (Kind::of($agent, isset($_SERVER['HTTP_SEC_FETCH_DEST']), isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) === Kind::BROWSER) {
            try {
                $insert = self::connect(self::file())->prepare('INSERT OR IGNORE INTO likes (page, ip_hash, time) VALUES (:page, :ip_hash, :time)');
                $insert->execute([
                    'page' => $post->uuid()->id(),
                    'ip_hash' => Fingerprint::ip((string) ($_SERVER['REMOTE_ADDR'] ?? '')),
                    'time' => (new DateTimeImmutable())->format('Y-m-d\TH:i:s.vP'),
                ]);
                if ($insert->rowCount() > 0) {
                    $post->uncache();
                }
            } catch (Throwable $e) {
                error_log('blog likes: ' . $e->getMessage());
            }
        }
        // 303 makes the browser load the post with GET. A reload then does
        // not send the form again.
        return Response::redirect($post->url() . '?liked#like', 303);
    }

    /** Parallel requests wait up to 5 seconds for the lock of the file. */
    public static function connect(string $file): Sqlite
    {
        Dir::make(dirname($file));
        $db = new Sqlite('sqlite:' . $file, null, null, [PDO::ATTR_TIMEOUT => 5]);
        $db->exec(self::SCHEMA);
        return $db;
    }
}
