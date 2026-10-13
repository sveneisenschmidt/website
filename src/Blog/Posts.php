<?php

namespace Website\Blog;

use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Website\Models\PostPage;

/**
 * The published posts and the lists made from them: the picks of the front
 * page, the timeline, the archive by month and the search.
 */
final class Posts
{
    /**
     * All published posts, newest first, posts of the same time by title.
     * The plugin registers this as the collection "posts".
     *
     * @return Pages<PostPage>
     */
    public static function collect(): Pages
    {
        return pages(Types::sections())->children()->listed()->sortBy('date', 'desc', 'title', 'asc');
    }

    /**
     * The collection "posts".
     *
     * @return Pages<PostPage>
     */
    public static function all(): Pages
    {
        /** @var Pages<PostPage> */
        return collection('posts');
    }

    /**
     * The picks with a cover, newest first: the front page shows them.
     *
     * @return Pages<PostPage>
     */
    public static function picks(): Pages
    {
        return self::all()->filter(fn(PostPage $post) => $post->pick()->toBool() && $post->coverImage() !== null);
    }

    /**
     * The timeline beside a page, as entries [post, label]: beside a post its
     * two neighbours, Next and Previous; beside any other page the newest
     * $limit posts, the first one Latest. neighbours says which of the two.
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
     * Posts that hold every word of $query in title, intro, text or the alt
     * text of an image, newest first, at most 40. KirbyText tags and link
     * targets do not count: a file name or URL would match almost anything.
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
