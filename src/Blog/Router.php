<?php

namespace Website\Blog;

use Kirby\Cms\Page;
use Kirby\Cms\Response;
use Website\Analytics\Campaign;
use Website\Models\PostPage;

/**
 * Resolves a request path to a page or a redirect. A post is at
 * /YYYY-MM-DD-slug/, every other page at its path with a slash at the end,
 * the home page at /. These URLs must not change, because links on other
 * sites and feed readers use them. SitePage and PostPage build the URLs.
 * config/routes.php sends every path here before Kirby's own routes.
 */
final class Router
{
    /**
     * Returns, in this order: a redirect from content/.redirects.json; the
     * post for /YYYY-MM-DD-slug/; for /YYYY-MM-DD-slug/<file>, a redirect to
     * the original file, because older links use that URL, also after the
     * post moved to a new URL; any other page, only at its URL with the slash
     * at the end. Returns null to let Kirby's own routes answer.
     */
    public static function resolve(string $path): Page|Response|null
    {
        if (($to = Redirects::target($path)) !== null) {
            // Url::to() turns the path "/" into the home URL without the slash.
            // Redirects::target() returns only paths of this site.
            return Response::redirect(kirby()->url('base') . $to, 301);
        }
        if (preg_match('!^(\d{4}-\d{2}-\d{2})-([a-z0-9-]+)(?:/([^/]+))?$!', $path, $m)) {
            $post = self::post($m[1], $m[2]);
            if (isset($m[3])) {
                $file = ($post ?? self::moved("$m[1]-$m[2]"))?->file($m[3]);
                return $file ? Response::redirect($file->url(), 301) : null;
            }
            return $post ? self::canonical($post, $path) : null;
        }
        // A path with a dot in its last part is a content representation, such
        // as search.json (templates/search.json.php). Kirby's own route answers it.
        $page = $path === '' || str_contains(basename($path), '.') ? null : kirby()->site()->find($path);
        return $page && !$page->isErrorPage() ? self::canonical($page, $path) : null;
    }

    /**
     * The page when $path is its URL, else a 301 redirect to its URL with the
     * query string. Kirby's router strips the slash at the end of $path, so
     * the check reads the path from REQUEST_URI.
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
        $query = http_build_query([...$kirby->request()->query()->toArray(), ...Campaign::params()]);
        return Response::redirect($url . ($query === '' ? '' : "?$query"), 301);
    }

    /** The post that a redirect from an old post path leads to, or null. */
    private static function moved(string $path): ?PostPage
    {
        $to = Redirects::target($path);
        return $to !== null && preg_match('!^/(\d{4}-\d{2}-\d{2})-([a-z0-9-]+)/?$!', $to, $m) ? self::post($m[1], $m[2]) : null;
    }

    /**
     * The post for a date and a slug, or null. A draft resolves only for a
     * Panel user who may open it, or for a request with the draft's preview
     * token, as in Kirby's own draft routing.
     */
    public static function post(string $date, string $slug): ?PostPage
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
