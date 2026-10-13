<?php

namespace Website\Blog;

use Kirby\Data\Json;
use Website\Models\PostPage;

/**
 * The redirects from old URLs, a list of from and to. They live in the
 * content, in every backup: state, like the change log. Router answers
 * them before any page.
 */
final class Redirects
{
    /** The store. Kirby skips dot files. */
    public static function file(): string
    {
        return kirby()->root('content') . '/.redirects.json';
    }

    /**
     * The redirects. With rss: keep the feed keeps the old URL as the
     * post's GUID (Feeds). Other keys are not read.
     *
     * @return list<array<string, string>>
     */
    public static function all(): array
    {
        $file = self::file();
        return is_file($file) ? Json::read($file) : [];
    }

    /**
     * Saves the redirects, through a temporary file.
     *
     * @param array<array<string, string>> $redirects
     */
    public static function write(array $redirects): void
    {
        JsonFile::write(self::file(), array_values($redirects));
    }

    /** Where an old path leads, or null. A path matches with or without the slash at the end. */
    public static function target(string $path): ?string
    {
        foreach (self::all() as $redirect) {
            if (trim($redirect['from'], '/') === trim($path, '/')) {
                return $redirect['to'];
            }
        }
        return null;
    }

    /**
     * Hook after a slug or date change: the old path of a published post
     * leads to the new one. Redirects to the old URL move on to the new one,
     * so there is never more than one step, and none starts at the new URL.
     * The feed keeps the post's GUID: the new redirect carries rss: keep,
     * unless a redirect to the old URL carries it already.
     */
    public static function moved(PostPage $post, string $from): void
    {
        $to = $post->postPath();
        if (!$post->isListed() || $from === $to) {
            return;
        }
        $guid = false;
        $redirects = [];
        foreach (self::all() as $redirect) {
            if ($redirect['to'] === $from) {
                $guid = $guid || ($redirect['rss'] ?? '') === 'keep';
                $redirect['to'] = $to;
            }
            if ($redirect['from'] !== $to) {
                $redirects[] = $redirect;
            }
        }
        $redirects[] = ['from' => $from, 'to' => $to] + ($guid ? [] : ['rss' => 'keep']);
        self::write($redirects);
    }
}
