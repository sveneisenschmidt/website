<?php

use Kirby\Cms\Page;
use Kirby\Cms\Response;
use Kirby\Data\Json;

/**
 * URLs as Hugo had them: a post at /YYYY-MM-DD-slug/, every other page at
 * its path with a slash at the end, the home page at /. Old URLs lead on
 * through the redirects in content/.redirects.json: state, like the
 * change log, started from data/redirects.yaml (make kirby-seed).
 */
class BlogPage extends Page
{
    public function url($options = null): string
    {
        if ($options !== null) {
            return parent::url($options);
        }
        $base = $this->kirby()->url('base');
        if ($this->isHomePage()) {
            return $base . '/';
        }
        if (blog_is_post($this)) {
            return $base . blog_post_path($this);
        }
        return $base . '/' . $this->uri() . '/';
    }
}

/** The path of a post, /YYYY-MM-DD-slug/, with another slug if given. */
function blog_post_path(Page $post, ?string $slug = null): string
{
    return '/' . $post->date()->toDate('Y-m-d') . '-' . ($slug ?? $post->slug()) . '/';
}

/**
 * The post at /YYYY-MM-DD-slug/, or null. A draft only for the Panel or
 * with its preview token, as Kirby resolves drafts.
 */
function blog_post(string $date, string $slug): ?Page
{
    foreach (BLOG_SECTIONS as $section) {
        $post = page($section)?->childrenAndDrafts()->find($slug);
        if ($post === null || $post->date()->toDate('Y-m-d') !== $date) {
            continue;
        }
        if ($post->isDraft() && !(kirby()->user() && $post->isAccessible()) && $post->renderVersionFromRequest() === null) {
            return null;
        }
        return $post;
    }
    return null;
}

/**
 * Answers a path before Kirby does, or null for Kirby's own routes:
 * a redirect, a post, an original image at its Hugo URL, every page only
 * at its URL with the slash at the end, as Apache served Hugo's folders.
 */
function blog_route(string $path): Page|Response|null
{
    if (($to = blog_redirect_to($path)) !== null) {
        // Absolute, so "/" keeps its slash.
        return Response::redirect(kirby()->url('base') . $to, 301);
    }
    if (preg_match('!^(\d{4}-\d{2}-\d{2})-([a-z0-9-]+)(?:/([^/]+))?$!', $path, $m)) {
        $post = blog_post($m[1], $m[2]);
        if ($post !== null && isset($m[3])) {
            $file = $post->file($m[3]);
            return $file ? Response::redirect($file->url(), 301) : null;
        }
        return $post ? blog_canonical($post, $path) : null;
    }
    // A path with an extension is a content representation (search.json): Kirby's.
    $page = $path === '' || str_contains(basename($path), '.') ? null : kirby()->site()->find($path);
    return $page && !$page->isErrorPage() ? blog_canonical($page, $path) : null;
}

/**
 * The page, if $path is its address; else a redirect there. The router
 * drops the slash at the end, so it comes from the request itself.
 */
function blog_canonical(Page $page, string $path): Page|Response
{
    $kirby = $page->kirby();
    $url = $page->url();
    $own = (string)parse_url($url, PHP_URL_PATH);
    $request = (string)parse_url((string)$kirby->environment()->get('REQUEST_URI'), PHP_URL_PATH);
    if (trim($own, '/') === $path && ($request === '' || $request === $own)) {
        return $page;
    }
    $query = $kirby->request()->query()->toString();
    return Response::redirect($url . ($query === '' ? '' : "?$query"), 301);
}

/** The redirects live in the content, in every backup. Kirby skips dot files. */
function blog_redirects_file(): string
{
    return kirby()->root('content') . '/.redirects.json';
}

/** The redirects: a list of from, to and Hugo's flags rss, pop and note. */
function blog_redirects(): array
{
    $file = blog_redirects_file();
    return is_file($file) ? Json::read($file) : [];
}

/** Writes to a temporary file first, as the visitor numbers do. */
function blog_redirects_write(array $redirects): void
{
    blog_analytics_write(blog_redirects_file(), array_values($redirects));
}

/** Where an old path leads, or null. As Hugo's RedirectMatch: with or without the slash at the end. */
function blog_redirect_to(string $path): ?string
{
    foreach (blog_redirects() as $redirect) {
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
function blog_redirect_moved(Page $post, string $from): void
{
    $to = blog_post_path($post);
    if (!blog_is_post($post) || !$post->isListed() || $from === $to) {
        return;
    }
    $guid = false;
    $redirects = [];
    foreach (blog_redirects() as $redirect) {
        if ($redirect['to'] === $from) {
            $guid = $guid || ($redirect['rss'] ?? '') === 'keep';
            $redirect['to'] = $to;
        }
        if ($redirect['from'] !== $to) {
            $redirects[] = $redirect;
        }
    }
    $redirects[] = ['from' => $from, 'to' => $to] + ($guid ? [] : ['rss' => 'keep']);
    blog_redirects_write($redirects);
}
