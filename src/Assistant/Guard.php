<?php

namespace Website\Assistant;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\HTMLElement;
use Dom\Node;
use Dom\Text;
use Kirby\Cms\Page;
use Kirby\Text\KirbyTag;
use Kirby\Uuid\Uuid;
use Website\Blog\Types;

/**
 * Checks every text Claude proposes, before a card exists. Text from photos,
 * GPX files, web pages or old posts can carry
 * instructions, so the code checks, not only the prompt. Each check returns
 * null when the text passes, else the error Claude gets back. No check
 * judges style.
 *
 * A text is judged by what the site makes of it: KirbyTags, then Kirby's
 * Markdown with the site's options, in the context of its page, read as a
 * browser reads HTML. Regexes on the source miss the links Markdown makes of
 * nested brackets, of reference definitions in lists and quotes, and of
 * link texts (security review, 2026-10-12). The source is checked as well:
 * a link target that renders as text here can render as a link next to
 * other text of the field.
 */
final class Guard
{
    /** The KirbyText tags a proposed text may hold. */
    public const TAGS = ['image', 'map', 'link'];

    /** Raw HTML: a tag, a closing tag, a comment, a doctype or a processing instruction. */
    public const HTML = '/<[a-z!\/?]/i';

    /** A KirbyText tag, found as Kirby\Text\KirbyTags::parse() finds it. */
    public const TAG = '!(?=[^\]])(?=\([a-z0-9_-]+:)(\((?:[^()]+|(?1))*+\))!is';

    /**
     * The elements a rendered text may hold, with the attributes Markdown gives
     * them: name => pattern of the value, null for any value. Links (href) are
     * checked on their own. figure, figcaption and img come only from (image:),
     * the map only from (map:).
     */
    public const ELEMENTS = [
        'p' => [], 'br' => [], 'em' => [], 'strong' => [], 'pre' => [], 'blockquote' => [], 'hr' => [],
        'ul' => [], 'li' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        // Markdown's own attributes: a list that starts at 3, the language of a code block.
        'ol' => ['start' => '/^\d{1,9}$/'],
        'code' => ['class' => '/^language-[\w#+.-]+$/'],
        'a' => ['href' => null, 'title' => null],
    ];

    /** The elements of a caption: Markdown inline, as (image:) renders it. */
    public const INLINE = ['br', 'em', 'strong', 'code', 'a'];

    /** The link targets a text may hold at all: http and https, mailto, and paths of this site. */
    public const SCHEMES = '#^(?:https?://[^/\\\\]|mailto:|/(?![/\\\\]))#i';

    /**
     * Every link target a text names: Markdown links and images, reference
     * definitions, bare URLs, whatever follows "](" or "]:" (in a list, a
     * quote or a code span it is text, next to other text a link), and what
     * Markdown makes a link. The URLs a message of the user allows.
     *
     * @return list<string>
     */
    public static function links(string $text): array
    {
        $links = [];
        $source = $text;
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
        preg_match_all('/\]\(\s*<?([^\s)>]+)|\]:\s*<?([^\s>]+)/', $source, $m);
        foreach ([...$m[1], ...$m[2]] as $target) {
            if ($target !== '') {
                $links[] = $target;
            }
        }
        return array_values(array_unique([...$links, ...self::hrefs(kirby()->markdown($source))]));
    }

    /** A page by its UUID ("page://…"), or null. */
    public static function page(string $uuid): ?Page
    {
        // Uuid::for() warns on a bare "page://"; the format comes first.
        if (!preg_match('#^page://[a-z0-9]+$#i', $uuid)) {
            return null;
        }
        $model = Uuid::for($uuid)?->model();
        return $model instanceof Page ? $model : null;
    }

    /**
     * A Markdown field of a post or of About ($page: its images and its map),
     * no HTML, three KirbyText tags, known links. $text is the field's new
     * value, $before its value now. $new is what Claude wrote, when that is a
     * passage of $text only: the rest of the field is not Claude's.
     *
     * @param list<string> $allowed
     */
    public static function markdown(string $text, array $allowed, Page $page, string $before = '', ?string $new = null): ?string
    {
        $new ??= $text;
        if (preg_match(self::HTML, $new)) {
            return 'Raw HTML is not allowed. Use Markdown.';
        }
        $files = Content::filenames($page);
        $map = Types::hasMap($page->intendedTemplate()->name());
        preg_match_all(self::TAG, $new, $m);
        foreach ($m[1] as $tag) {
            if (($error = self::tag($tag, $files, $map)) !== null) {
                return $error;
            }
        }
        return self::targets($new, [...$allowed, ...self::links($before)])
            ?? self::rendered($text, $allowed, $page, $before);
    }

    /**
     * A caption: Markdown links by the same rule, otherwise plain text, as (image:) renders it.
     *
     * @param list<string> $allowed
     */
    public static function caption(string $text, array $allowed, string $before = ''): ?string
    {
        if (preg_match(self::HTML, $text)) {
            return 'Raw HTML is not allowed.';
        }
        if (str_contains($text, '![')) {
            return 'A caption holds no image.';
        }
        preg_match_all(self::TAG, $text, $m);
        foreach ($m[1] as $tag) {
            if (self::tagType($tag) !== null) {
                return 'A caption holds no KirbyText tags.';
            }
        }
        return self::targets($text, [...$allowed, ...self::links($before)])
            ?? self::rendered($text, $allowed, null, $before, true);
    }

    /**
     * The links of a text as a card marks them: each link target as the text
     * writes it, and each (link:) tag. The checks above make sure that every
     * link of a new text is one of them. $caption: rendered inline, as
     * (image:) renders a caption.
     *
     * @return list<string>
     */
    public static function marks(string $text, ?Page $page = null, bool $caption = false): array
    {
        if (trim($text) === '') {
            return [];
        }
        $marks = [];
        foreach (self::body(self::render($text, $page, $caption))->querySelectorAll('a[href]') as $link) {
            $href = (string) $link->getAttribute('href');
            if ($href !== '' && $link->closest('figure') === null && str_contains($text, $href)) {
                $marks[] = $href;
            }
        }
        return array_values(array_unique([...$marks, ...array_keys(self::pageLinks($text))]));
    }

    /**
     * Any proposed value: no run of 8 words of the chat's prompt, by the same
     * words as the git hook counts them (Words). A card can end up on the
     * site, and the prompt never does.
     *
     * @param array<string, true> $runs
     */
    public static function prompt(string $text, array $runs): ?string
    {
        return $runs !== [] && array_intersect_key(Words::runs($text), $runs) !== []
            ? 'This text repeats 8 or more words in a row of the system prompt. Proposals never quote it.'
            : null;
    }

    /** Plain text: title, alt text, photographer, trip, map labels. $max: characters, 0 for any. */
    public static function plain(string $text, int $max = 0): ?string
    {
        if (preg_match(self::HTML, $text)) {
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

    /** The error for a link that is not allowed. */
    private static function linkError(string $target): string
    {
        return "The link $target is not allowed. Links go only to URLs from the user's messages, from web search results of this chat, or already in the field.";
    }

    /** HTML of a text as the site renders it: KirbyTags, then Markdown, in the context of $parent. */
    private static function render(string $text, ?Page $parent, bool $inline = false): string
    {
        $options = $inline ? ['markdown' => ['inline' => true]] : [];
        return kirby()->kirbytext($text, $parent === null ? $options : ['parent' => $parent] + $options);
    }

    /** The body of rendered HTML as a browser builds it (HTML5). */
    private static function body(string $html): HTMLElement
    {
        return HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html, LIBXML_NOERROR, 'UTF-8')->body;
    }

    /**
     * Every href and src of rendered HTML.
     *
     * @return list<?string>
     */
    private static function hrefs(string $html): array
    {
        $urls = [];
        foreach (self::body($html)->querySelectorAll('[href], [src]') as $element) {
            $urls[] = $element->getAttribute('href') ?? $element->getAttribute('src');
        }
        return $urls;
    }

    /**
     * Every target must be one of $allowed: from the user's messages, the web search of the chat or the field.
     *
     * @param list<string> $allowed
     */
    private static function targets(string $text, array $allowed): ?string
    {
        foreach (self::links($text) as $target) {
            if (!in_array($target, $allowed, true)) {
                return self::linkError($target);
            }
        }
        return null;
    }

    /** The type of a KirbyText tag when Kirby knows it, else null: "(https://…)" or "(Update: …)" stay text. */
    private static function tagType(string $tag): ?string
    {
        $type = strtolower(trim(substr($tag, 1, (int) strpos($tag, ':') - 1)));
        return isset(KirbyTag::$types[$type]) || isset(KirbyTag::$aliases[$type]) ? $type : null;
    }

    /**
     * One KirbyText tag of a Markdown field. $files: the image file names of the post.
     *
     * @param list<string> $files
     */
    private static function tag(string $tag, array $files, bool $map): ?string
    {
        $type = self::tagType($tag);
        if ($type === null) {
            return null;
        }
        if (!in_array($type, self::TAGS, true)) {
            return "The KirbyText tag ($type:) is not allowed. Allowed are (image: file name), (map:) and (link: page://… text: …).";
        }
        $parsed = KirbyTag::parse($tag);
        $value = trim((string) $parsed->value);
        $attrs = array_keys(array_filter($parsed->attrs, fn($v) => trim((string) $v) !== ''));
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
        if (self::page($value) === null) {
            return "(link: $value) must point to an existing page as page://<uuid>.";
        }
        if (array_diff($attrs, ['text']) !== []) {
            return '(link:) takes only text: besides the page.';
        }
        return preg_match(self::HTML, (string) ($parsed->attrs['text'] ?? '')) ? 'Raw HTML is not allowed.' : null;
    }

    /**
     * The URLs the (link: page://…) tags of a text render: tag => URL.
     *
     * @return array<string, string>
     */
    private static function pageLinks(string $text): array
    {
        $urls = [];
        preg_match_all(self::TAG, $text, $m);
        foreach ($m[1] as $tag) {
            if (self::tagType($tag) === 'link' && ($page = self::page(trim((string) KirbyTag::parse($tag)->value))) !== null) {
                $urls[$tag] = $page->url();
            }
        }
        return $urls;
    }

    /**
     * The rules for the rendered HTML of a field of $page. $text and $before
     * are the field's new value and its value now. A link may go to a URL of
     * $allowed or of the field now, if it is http, https, mailto or a path of
     * this site, and to a page of a (link:) tag. An (image:) or (map:) tag of
     * the text renders as the site renders it: figure, EXIF rows and caption,
     * or the map. tag() has checked the tag itself.
     *
     * @param list<string> $allowed
     * @return array<string, mixed>
     */
    private static function rules(string $text, array $allowed, ?Page $page, string $before, bool $caption = false): array
    {
        $old = $before === '' ? [] : self::hrefs(self::render($before, $page, $caption));
        $known = array_flip([...$allowed, ...$old]);
        $produced = array_flip(self::pageLinks($text));
        $tags = [];
        if (!$caption && $page !== null) {
            preg_match_all(self::TAG, $text, $m);
            foreach ($m[1] as $tag) {
                if (in_array(self::tagType($tag), ['image', 'map'], true)) {
                    foreach (self::body(self::render($tag, $page))->children as $element) {
                        $tags[self::html($element)] = true;
                    }
                }
            }
        }
        return [
            'elements' => $caption ? array_intersect_key(self::ELEMENTS, array_flip(self::INLINE)) : self::ELEMENTS,
            'link' => fn(string $url) => isset($produced[$url])
                || (isset($known[$url]) && preg_match(self::SCHEMES, $url) && !preg_match('/[\x00-\x20\x7F]/', $url)),
            'produced' => $produced,
            'tags' => $tags,
            'source' => $text,
        ];
    }

    /**
     * True when every attribute of $element is one of $allowed (name => pattern or null) and matches.
     *
     * @param array<string, ?string> $allowed
     */
    private static function attributes(Element $element, array $allowed): bool
    {
        foreach ($element->attributes as $attribute) {
            if (!array_key_exists($attribute->name, $allowed)) {
                return false;
            }
            $pattern = $allowed[$attribute->name];
            if ($pattern !== null && !preg_match($pattern, $attribute->value)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Walks rendered HTML. Adds each problem as [error, markup] to $problems.
     * A link outside an image must stand in the text as written, so the card
     * can mark it; a (link:) tag stands for the page it renders.
     *
     * @param array<string, mixed> $rules
     * @param list<array{string, string}> $problems
     */
    private static function walk(Node $parent, array $rules, array &$problems): void
    {
        foreach ($parent->childNodes as $node) {
            if ($node instanceof Text) {
                continue;
            }
            $markup = self::html($node);
            if (!$node instanceof Element) {
                $problems[] = ['Raw HTML is not allowed. Use Markdown.', $markup];
                continue;
            }
            $name = $node->localName;
            if (isset($rules['tags'][$markup])) {
                continue;
            }
            if (!isset($rules['elements'][$name])) {
                $problems[] = [in_array($name, ['img', 'figure', 'figcaption'], true)
                    ? 'Images go in as (image: file name) of this post, captions in its caption field.'
                    : "The text renders the element <$name>, which is not possible here. Use Markdown: paragraphs, emphasis, lists, quotes, code, headings from ## on, rules and links.", $markup];
                continue;
            }
            if (!self::attributes($node, $rules['elements'][$name])) {
                $problems[] = ["The text renders <$name> with attributes that are not possible here.", $markup];
                continue;
            }
            if ($name === 'a') {
                $href = (string) $node->getAttribute('href');
                if (!$rules['link']($href)) {
                    $problems[] = [self::linkError($href), $markup];
                    continue;
                }
                if ($rules['source'] !== null && !isset($rules['produced'][$href]) && !str_contains($rules['source'], $href)) {
                    $problems[] = ["The link $href renders from text that does not show it. Write the URL out as it is.", $markup];
                    continue;
                }
            }
            self::walk($node, $rules, $problems);
        }
    }

    /**
     * Checks the HTML a text renders to. What $before renders already, the
     * markup of imported posts included, may stay as it is, once each.
     *
     * @param list<string> $allowed
     */
    private static function rendered(string $text, array $allowed, ?Page $page, string $before = '', bool $caption = false): ?string
    {
        $rules = self::rules($text, $allowed, $page, $before, $caption);
        $problems = [];
        self::walk(self::body(self::render($text, $page, $caption)), $rules, $problems);
        if ($problems === []) {
            return null;
        }
        $old = [];
        if ($before !== '') {
            self::walk(self::body(self::render($before, $page, $caption)), ['source' => $before] + $rules, $old);
        }
        $kept = array_count_values(array_column($old, 1));
        foreach ($problems as [$error, $markup]) {
            if (($kept[$markup] ?? 0) === 0) {
                return $error;
            }
            $kept[$markup]--;
        }
        return null;
    }

    /** The HTML of one node, as its document writes it. body() makes every document an HTMLDocument. */
    private static function html(Node $node): string
    {
        $document = $node->ownerDocument;
        assert($document instanceof HTMLDocument);
        return $document->saveHtml($node);
    }
}
