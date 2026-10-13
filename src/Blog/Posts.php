<?php

namespace Website\Blog;

use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Website\Models\PostPage;

/**
 * Lists of published posts for templates, snippets and controllers: the
 * featured posts of the front page, the timeline (the list of posts beside
 * the content, snippets/stream.php), the archive by month and the search.
 */
final class Posts
{
    /**
     * Builds the Kirby collection "posts", which plugins/blog registers: all
     * published posts, newest first. Posts with the same date and time sort
     * by title.
     *
     * @return Pages<PostPage>
     */
    public static function collect(): Pages
    {
        return pages(Types::sections())->children()->listed()->sortBy('date', 'desc', 'title', 'asc');
    }

    /**
     * All published posts, newest first. Kirby's collection() builds the
     * list once per request and returns a copy on every later call.
     *
     * @return Pages<PostPage>
     */
    public static function all(): Pages
    {
        /** @var Pages<PostPage> */
        return collection('posts');
    }

    /**
     * The featured posts (the toggle "Hervorgehoben", field `pick`) that
     * have a cover, newest first. The front page shows them.
     *
     * @return Pages<PostPage>
     */
    public static function picks(): Pages
    {
        return self::all()->filter(fn(PostPage $post) => $post->pick()->toBool() && $post->coverImage() !== null);
    }

    /**
     * The entries of the timeline beside a page, each [post, label]. Beside
     * a published post: the newer post labelled Next and the older one
     * labelled Previous. Beside any other page: the newest $limit posts, the
     * first one labelled Latest. The key `neighbours` is true in the first case.
     *
     * @return array{neighbours: bool, entries: list<array{PostPage, string}>}
     */
    public static function timeline(?Page $current, int $limit): array
    {
        $posts = self::all();
        if ($current instanceof PostPage && $posts->has($current->id())) {
            return ['neighbours' => true, 'entries' => $current->neighbours()];
        }
        $entries = [];
        foreach ($posts->limit($limit) as $post) {
            $entries[] = [$post, $entries === [] ? 'Latest' : ''];
        }
        return ['neighbours' => false, 'entries' => $entries];
    }

    /**
     * Posts by month in their order, "October 2026" => [post, ...].
     *
     * @param Pages<PostPage> $posts
     * @return array<string, list<PostPage>>
     */
    public static function months(Pages $posts): array
    {
        $months = [];
        foreach ($posts as $post) {
            $months[$post->date()->toDate('F Y')][] = $post;
        }
        return $months;
    }

    /**
     * Posts that contain every word of $query in title, intro, text or the
     * alt text of an image, newest first, at most 40. KirbyText tags such as
     * (image: photo.jpg) and the targets of Markdown links are removed first,
     * because a file name or URL would match almost any query.
     *
     * @return Pages<PostPage>
     */
    public static function search(string $query): Pages
    {
        $words = preg_split('/\s+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY);
        if ($words === []) {
            return new Pages([]);
        }
        return self::all()->filter(function (PostPage $post) use ($words) {
            $text = $post->title()->value() . ' ' . $post->intro()->value() . ' ' . $post->text()->value();
            $text = preg_replace(['/\([a-z]+:[^)]*\)/', '/\]\([^)]*\)/'], [' ', ']'], $text);
            $text = mb_strtolower($text . ' ' . implode(' ', $post->images()->pluck('alt')));
            foreach ($words as $word) {
                if (!str_contains($text, $word)) {
                    return false;
                }
            }
            return true;
        })->limit(40);
    }
}
