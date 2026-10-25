<?php

namespace Website\Blog;

use Kirby\Data\Json;
use Throwable;
use Website\Models\PostPage;

/**
 * The redirects from old URLs to new ones, a list of from/to pairs in
 * content/.redirects.json. The file is in content/, so every backup has it
 * and git does not. Router checks the redirects before it looks for a page.
 */
final class Redirects
{
    /** The path of the JSON file. Kirby ignores files in content/ whose name starts with a dot. */
    public static function file(): string
    {
        return kirby()->root('content') . '/.redirects.json';
    }

    /**
     * All redirects. For a redirect with `rss: keep`, Feeds uses the old URL
     * as the post's GUID. No code reads other keys.
     *
     * @return list<array<string, string>>
     */
    public static function all(): array
    {
        $file = self::file();
        return is_file($file) ? Json::read($file) : [];
    }

    /**
     * @param array<array<string, string>> $redirects
     */
    public static function write(array $redirects): void
    {
        JsonFile::write(self::file(), array_values($redirects));
    }

    /**
     * The new path for an old path, or null. A path matches with or without
     * the slash at the end. Router puts the base URL in front of the target,
     * so a target must start with "/": a target such as ".example.com" would
     * lead to another host. A broken file gives null for every path, so the
     * pages stay up. The writers still fail on it, so no write replaces the
     * redirects with an empty list.
     */
    public static function target(string $path): ?string
    {
        try {
            $redirects = self::all();
        } catch (Throwable $e) {
            error_log('blog redirects: ' . $e->getMessage());
            return null;
        }
        foreach ($redirects as $redirect) {
            if (trim($redirect['from'], '/') === trim($path, '/')) {
                $to = $redirect['to'];
                return str_starts_with($to, '/') && !str_starts_with($to, '//') ? $to : null;
            }
        }
        return null;
    }

    /**
     * Adds a redirect from the old path of a published post to its new path.
     * plugins/blog calls it after a change of slug or date. Redirects to the
     * old path now point to the new one, so a visitor never follows two
     * redirects in a row. A redirect from the new path is removed, because
     * it would send visitors away from the post. The new redirect gets
     * `rss: keep`, so the feed keeps the post's GUID. When a redirect to the
     * old path has `rss: keep` already, that redirect keeps the GUID instead.
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

    /** Removes the redirects from $from. Redirects from other paths stay. */
    public static function remove(string $from): void
    {
        self::write(array_filter(self::all(), fn($redirect) => $redirect['from'] !== $from));
    }
}
