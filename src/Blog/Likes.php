<?php

namespace Website\Blog;

use DateTimeImmutable;
use Kirby\Cms\Response;
use Kirby\Filesystem\Dir;
use PDO;
use Pdo\Sqlite;
use Website\Analytics\Fingerprint;
use Website\Analytics\Kind;
use Website\Models\PostPage;

/**
 * The likes of the posts, in the SQLite database content/.likes.sqlite. The
 * file is in content/, so every backup has it and git does not. The like
 * button in templates/post.php is a form that sends POST to
 * /YYYY-MM-DD-slug/like (config/routes.php). It works without JavaScript and
 * without a cookie. A post counts one like per IP address.
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

    public static function count(PostPage $post): int
    {
        if (!is_file(self::file())) {
            return 0;
        }
        $query = self::connect(self::file())->prepare('SELECT COUNT(*) FROM likes WHERE page = :page');
        $query->execute(['page' => $post->uuid()->id()]);
        return (int) $query->fetchColumn();
    }

    /** True when the IP address $ip has liked the post. */
    public static function has(PostPage $post, string $ip): bool
    {
        if (!is_file(self::file())) {
            return false;
        }
        $query = self::connect(self::file())->prepare('SELECT 1 FROM likes WHERE page = :page AND ip_hash = :ip_hash');
        $query->execute(['page' => $post->uuid()->id(), 'ip_hash' => Fingerprint::ip($ip)]);
        return $query->fetchColumn() !== false;
    }

    /**
     * The answer to POST /YYYY-MM-DD-slug/like: it stores the like and leads
     * back to the post, or gives null for no published post. A request that
     * Kind does not count as a browser leads back without a like. A new like
     * removes the post from the page cache, so the next visitor sees the new
     * count. ?liked lets the post say "Liked" to this address (see
     * controllers/post.php), and #like scrolls to the form.
     */
    public static function route(string $date, string $slug): ?Response
    {
        $post = Router::post($date, $slug);
        if ($post === null || !$post->isListed()) {
            return null;
        }
        $agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (Kind::of($agent, isset($_SERVER['HTTP_SEC_FETCH_DEST']), isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) === Kind::BROWSER) {
            $insert = self::connect(self::file())->prepare('INSERT OR IGNORE INTO likes (page, ip_hash, time) VALUES (:page, :ip_hash, :time)');
            $insert->execute([
                'page' => $post->uuid()->id(),
                'ip_hash' => Fingerprint::ip((string) ($_SERVER['REMOTE_ADDR'] ?? '')),
                'time' => (new DateTimeImmutable())->format('Y-m-d\TH:i:s.vP'),
            ]);
            if ($insert->rowCount() > 0) {
                $post->uncache();
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
