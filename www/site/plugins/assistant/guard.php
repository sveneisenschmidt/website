<?php

use Kirby\Cms\Page;
use Kirby\Text\KirbyTag;
use Kirby\Uuid\Uuid;

/**
 * Checks every text Claude proposes, before a card exists (spec, "Prüfung
 * der Texte"). Text from photos, GPX files, web pages or old posts can carry
 * instructions, so the code checks, not only the prompt. Each check returns
 * null when the text passes, else the error Claude gets back. No check
 * judges style.
 */

/** The KirbyText tags a proposed text may hold. */
const BLOG_GUARD_TAGS = ['image', 'map', 'link'];

/** Raw HTML: a tag, a closing tag, a comment, a doctype or a processing instruction. */
const BLOG_GUARD_HTML = '/<[a-z!\/?]/i';

/** A KirbyText tag, found as Kirby\Text\KirbyTags::parse() finds it. */
const BLOG_GUARD_TAG = '!(?=[^\]])(?=\([a-z0-9_-]+:)(\((?:[^()]+|(?1))*+\))!is';

/** Every link target of a text: Markdown links and images, reference definitions, bare URLs. */
function blog_guard_links(string $text): array
{
    $links = [];
    // [text](target) and ![alt](target), with an optional title.
    $text = preg_replace_callback('/!?\[(?:[^\]\\\\]|\\\\.)*\]\(\s*<?([^\s)>]+)>?(?:\s+(?:"[^"]*"|\'[^\']*\'|\([^)]*\)))?\s*\)/', function (array $m) use (&$links) {
        $links[] = $m[1];
        return ' ';
    }, $text);
    // [label]: target
    $text = preg_replace_callback('/^ {0,3}\[[^\]]+\]:\s*<?([^\s>]+)>?/m', function (array $m) use (&$links) {
        $links[] = $m[1];
        return ' ';
    }, $text);
    // Kirby's Markdown turns bare URLs into links as well.
    preg_match_all('!\bhttps?://[^\s<]+!i', $text, $m);
    foreach ($m[0] as $url) {
        $links[] = rtrim($url, '.,;:!?)\'"');
    }
    return array_values(array_unique($links));
}

/** Every target must be one of $allowed: from the user's messages, the web search of the chat or the field. */
function blog_guard_targets(string $text, array $allowed): ?string
{
    foreach (blog_guard_links($text) as $target) {
        if (!in_array($target, $allowed, true)) {
            return "The link $target is not allowed. Links go only to URLs from the user's messages, from web search results of this chat, or already in the field.";
        }
    }
    return null;
}

/** The type of a KirbyText tag when Kirby knows it, else null: "(https://…)" or "(Update: …)" stay text. */
function blog_guard_tag_type(string $tag): ?string
{
    $type = strtolower(trim(substr($tag, 1, (int)strpos($tag, ':') - 1)));
    return isset(KirbyTag::$types[$type]) || isset(KirbyTag::$aliases[$type]) ? $type : null;
}

/** A page by its UUID ("page://…"), or null. */
function blog_guard_page(string $uuid): ?Page
{
    // Uuid::for() warns on a bare "page://"; the format comes first.
    if (!preg_match('#^page://[a-z0-9]+$#i', $uuid)) {
        return null;
    }
    $model = Uuid::for($uuid)?->model();
    return $model instanceof Page ? $model : null;
}

/** One KirbyText tag of a Markdown field. $files: the image file names of the post. */
function blog_guard_tag(string $tag, array $files, bool $map): ?string
{
    $type = blog_guard_tag_type($tag);
    if ($type === null) {
        return null;
    }
    if (!in_array($type, BLOG_GUARD_TAGS, true)) {
        return "The KirbyText tag ($type:) is not allowed. Allowed are (image: file name), (map:) and (link: page://… text: …).";
    }
    $parsed = KirbyTag::parse($tag);
    $value = trim((string)$parsed->value);
    $attrs = array_keys(array_filter($parsed->attrs, fn ($v) => trim((string)$v) !== ''));
    if ($type === 'image') {
        if (!in_array($value, $files, true)) {
            return "(image: $value) names no image of this post. Images: " . ($files === [] ? 'none' : implode(', ', $files)) . '.';
        }
        return array_diff($attrs, ['imgclass']) === [] ? null : '(image:) takes no attributes besides imgclass.';
    }
    if ($type === 'map') {
        if (!$map) {
            return '(map:) is possible in photo and trip posts only.';
        }
        return $value === '' && $attrs === [] ? null : '(map:) takes no value and no attributes.';
    }
    if (blog_guard_page($value) === null) {
        return "(link: $value) must point to an existing page as page://<uuid>.";
    }
    if (array_diff($attrs, ['text']) !== []) {
        return '(link:) takes only text: besides the page.';
    }
    return preg_match(BLOG_GUARD_HTML, (string)($parsed->attrs['text'] ?? '')) ? 'Raw HTML is not allowed.' : null;
}

/** A Markdown field of a post or of About: no HTML, three KirbyText tags, known links. */
function blog_guard_markdown(string $text, array $allowed, array $files, bool $map): ?string
{
    if (preg_match(BLOG_GUARD_HTML, $text)) {
        return 'Raw HTML is not allowed. Use Markdown.';
    }
    preg_match_all(BLOG_GUARD_TAG, $text, $m);
    foreach ($m[1] as $tag) {
        if (($error = blog_guard_tag($tag, $files, $map)) !== null) {
            return $error;
        }
    }
    return blog_guard_targets($text, $allowed);
}

/** A caption: Markdown links by the same rule, otherwise plain text. */
function blog_guard_caption(string $text, array $allowed): ?string
{
    if (preg_match(BLOG_GUARD_HTML, $text)) {
        return 'Raw HTML is not allowed.';
    }
    if (str_contains($text, '![')) {
        return 'A caption holds no image.';
    }
    preg_match_all(BLOG_GUARD_TAG, $text, $m);
    foreach ($m[1] as $tag) {
        if (blog_guard_tag_type($tag) !== null) {
            return 'A caption holds no KirbyText tags.';
        }
    }
    return blog_guard_targets($text, $allowed);
}

/** Plain text: title, alt text, photographer, trip, map labels. $max: characters, 0 for any. */
function blog_guard_plain(string $text, int $max = 0): ?string
{
    if (preg_match(BLOG_GUARD_HTML, $text)) {
        return 'Raw HTML is not allowed.';
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $text)) {
        return 'Plain text holds no line breaks or control characters.';
    }
    if ($max > 0 && mb_strlen($text) > $max) {
        return "At most $max characters.";
    }
    return null;
}
