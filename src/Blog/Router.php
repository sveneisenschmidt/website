<?php

namespace Website\Blog;

use Kirby\Cms\Page;
use Kirby\Cms\Response;
use Website\Models\PostPage;

/**
 * The URL scheme of the site, which links and feed readers know and which
 * stays as it is: a post at /YYYY-MM-DD-slug/, every other page at its
 * path with a slash at the end, the home page at /. The page models build
 * these URLs (SitePage, PostPage); config/routes.php sends every path here
 * before Kirby's own routes.
 */
final class Router
{
    /**
     * Answers a path before Kirby does, or null for Kirby's own routes:
     * a redirect, a post, an original image at its old URL next to its post
     * (a redirect to the file), every page only at its URL with the slash at
     * the end.
     */
    public static function resolve(string $path): Page|Response|null
    {
        if (($to = Redirects::target($path)) !== null) {
            // Absolute, so "/" keeps its slash.
            return Response::redirect(kirby()->url('base') . $to, 301);
        }
        if (preg_match('!^(\d{4}-\d{2}-\d{2})-([a-z0-9-]+)(?:/([^/]+))?$!', $path, $m)) {
            $post = self::post($m[1], $m[2]);
            if ($post !== null && isset($m[3])) {
                $file = $post->file($m[3]);
                return $file ? Response::redirect($file->url(), 301) : null;
            }
            return $post ? self::canonical($post, $path) : null;
        }
        // A path with an extension is a content representation (search.json): Kirby's.
        $page = $path === '' || str_contains(basename($path), '.') ? null : kirby()->site()->find($path);
        return $page && !$page->isErrorPage() ? self::canonical($page, $path) : null;
    }

    /**
     * The page, if $path is its address; else a redirect there. The router
     * drops the slash at the end, so it comes from the request itself.
     */
    public static function canonical(Page $page, string $path): Page|Response
    {
        $kirby = $page->kirby();
        $url = $page->url();
        $own = (string) parse_url($url, PHP_URL_PATH);
        $request = (string) parse_url((string) $kirby->environment()->get('REQUEST_URI'), PHP_URL_PATH);
        if (trim($own, '/') === $path && ($request === '' || $request === $own)) {
            return $page;
        }
        $query = $kirby->request()->query()->toString();
        return Response::redirect($url . ($query === '' ? '' : "?$query"), 301);
    }

    /**
     * The post at /YYYY-MM-DD-slug/, or null. A draft only for the Panel or
     * with its preview token, as Kirby resolves drafts.
     */
    private static function post(string $date, string $slug): ?PostPage
    {
        foreach (Types::sections() as $section) {
            $post = page($section)?->childrenAndDrafts()->find($slug);
            if (!$post instanceof PostPage || $post->date()->toDate('Y-m-d') !== $date) {
                continue;
            }
            if ($post->isDraft() && !(kirby()->user() && $post->isAccessible()) && $post->renderVersionFromRequest() === null) {
                return null;
            }
            return $post;
        }
        return null;
    }
}
