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
 * Checks every value that Claude proposes, before a card exists. A card is a
 * change that Claude proposed; the chat shows it with buttons to apply or
 * discard it. Text from photos, GPX files, web pages or older posts can
 * contain instructions for Claude (prompt injection), so the code checks
 * each proposal and does not rely on the prompt alone. Each check returns
 * null when the text passes, else the error message that Claude gets back.
 * No check judges style.
 *
 * A text is checked by the HTML the site renders from it: KirbyTags first,
 * then Kirby's Markdown with the site's options, in the context of its page,
 * parsed as a browser parses HTML. Regular expressions on the source miss
 * the links that Markdown makes from nested brackets, from reference
 * definitions in lists and quotes, and from link texts (security review of
 * 2026-10-12). The source is checked as well: a link target that renders as
 * text in the new value can render as a link next to other text of the field.
 *
 * Text from outside reaches Claude through these inputs: the EXIF strings
 * for camera and lens and the cleaned file name of an upload (Uploads), the
 * page titles in the context line (Chat), every field of a post, drafts and
 * unsaved changes included (Content), and web search results. A new input
 * needs the same checks.
 */
final class Guard
{
    /** The KirbyText tags a proposed text may contain. */
    public const TAGS = ['image', 'map', 'link'];

    /** The start of raw HTML: a tag, a closing tag, a comment, a doctype or a processing instruction. */
    public const HTML = '/<[a-z!\/?]/i';

    /**
     * A KirbyText tag, with the pattern of `Kirby\Text\KirbyTags::parse()`,
     * so the check sees the tags that Kirby renders.
     */
    public const TAG = '!(?=[^\]])(?=\([a-z0-9_-]+:)(\((?:[^()]+|(?1))*+\))!is';

    /**
     * The elements a rendered text may contain, with the attributes Markdown
     * gives them: name => pattern for the value, or null for any value.
     * `walk()` checks the href of a link separately. figure, figcaption and
     * img pass only as (image:) renders them, the map only as (map:) renders
     * it.
     */
    public const ELEMENTS = [
        'p' => [], 'br' => [], 'em' => [], 'strong' => [], 'pre' => [], 'blockquote' => [], 'hr' => [],
        'ul' => [], 'li' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        // Attributes that Markdown itself writes: the start number of an
        // ordered list, the language of a code block.
        'ol' => ['start' => '/^\d{1,9}$/'],
        'code' => ['class' => '/^language-[\w#+.-]+$/'],
        'a' => ['href' => null, 'title' => null],
    ];

    /** The elements a caption may contain: (image:) renders a caption as inline Markdown. */
    public const INLINE = ['br', 'em', 'strong', 'code', 'a'];

    /**
     * The link targets a text may contain at all: http and https, mailto, and
     * paths of this site. A path that starts with // or /\ leads to another
     * host in a browser, so it does not count as a path.
     */
    public const SCHEMES = '#^(?:https?://[^/\\\\]|mailto:|/(?![/\\\\]))#i';

    /**
     * Every link target a text names: Markdown links and images, reference
     * definitions, bare URLs, anything after "](" or "]:", and every href and
     * src of the rendered Markdown. A target after "](" or "]:" counts even
     * where it renders as text (in a list, a quote, a code span), because
     * next to other text it can render as a link. `Chat::send()` takes the
     * URLs of a message of the Panel user from this list.
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
        // Uuid::for() raises a warning for a bare "page://", so the format
        // check comes first.
        if (!preg_match('#^page://[a-z0-9]+$#i', $uuid)) {
            return null;
        }
        $model = Uuid::for($uuid)?->model();
        return $model instanceof Page ? $model : null;
    }

    /**
     * Checks a Markdown field of a post or of About: no raw HTML, only the
     * tags of `TAGS`, only known links. $page gives the images and the map
     * that the tags may use. $text is the new value of the field and $before
     * its current value. $new is the part that Claude wrote when it replaces
     * only a passage of $text. The checks of raw HTML, tags and link targets
     * then look at $new only, because the rest of the field is not Claude's
     * text. The check of the rendered HTML covers the whole field.
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
     * Checks a caption. Markdown links follow the same rule as in
     * `markdown()`; everything else must be plain text, as (image:) renders
     * a caption.
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
     * The parts of a text that a card highlights as links: each link target
     * as the text writes it, and each (link:) tag. The checks of this class
     * reject a new text with any other link, so the Panel user sees every
     * link before the click. $caption renders the text inline, as (image:)
     * renders a caption.
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
     * Rejects a proposed value that contains a run of 8 words from the prompt
     * of the chat. It counts words as the git hook does (`Words`). A card can
     * end up on the public site, and the prompt must not.
     *
     * @param array<string, true> $runs
     */
    public static function prompt(string $text, array $runs): ?string
    {
        return $runs !== [] && array_intersect_key(Words::runs($text), $runs) !== []
            ? 'This text repeats 8 or more words in a row of the system prompt. Proposals never quote it.'
            : null;
    }

    /**
     * Checks plain text such as a title, an alt text, a photographer, a trip
     * or a map label. $max is the most characters allowed, 0 for no limit.
     */
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

    private static function linkError(string $target): string
    {
        return "The link $target is not allowed. Links go only to URLs from the user's messages, from web search results of this chat, or already in the field.";
    }

    /**
     * The HTML of a text as the site renders it: KirbyTags, then Markdown.
     * (image:) and (map:) need $parent to find the files and the map of the
     * page.
     */
    private static function render(string $text, ?Page $parent, bool $inline = false): string
    {
        $options = $inline ? ['markdown' => ['inline' => true]] : [];
        return kirby()->kirbytext($text, $parent === null ? $options : ['parent' => $parent] + $options);
    }

    /** Parses rendered HTML with PHP's HTML5 parser, which builds the same tree as a browser. */
    private static function body(string $html): HTMLElement
    {
        return HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html, LIBXML_NOERROR, 'UTF-8')->body;
    }

    /**
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
     * The error for the first link target of $text that is not in $allowed.
     * $allowed holds the URLs from the messages of the Panel user, from the
     * web search of the chat and from the current field.
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

    /**
     * The type of a KirbyText tag when Kirby knows it, else null. Text in
     * parentheses such as "(https://…)" or "(Update: …)" matches `TAG` too,
     * but Kirby renders it as text.
     */
    private static function tagType(string $tag): ?string
    {
        $type = strtolower(trim(substr($tag, 1, (int) strpos($tag, ':') - 1)));
        return isset(KirbyTag::$types[$type]) || isset(KirbyTag::$aliases[$type]) ? $type : null;
    }

    /**
     * Checks one KirbyText tag of a Markdown field. $files holds the image
     * file names of the post; $map is true when the post type has a map.
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
     * The rules that `walk()` applies to the rendered HTML of a field of
     * $page. $text and $before are the new and the current value of the
     * field. A link may go to a URL from $allowed or from the current field
     * when the URL is http, https, mailto or a path of this site. A link may
     * also go to the page of a (link:) tag. The HTML of an (image:) or (map:)
     * tag passes as a whole (figure, EXIF rows and caption, or the map),
     * because `tag()` has checked the tag itself.
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
     * Checks rendered HTML node by node and adds each problem as [error,
     * markup] to $problems. A link outside an image must appear in the source
     * text as written, so the card can highlight it. A (link:) tag counts for
     * the URL of its page.
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
     * Checks the HTML that a text renders to. A problem that the current
     * value ($before) has already may stay, as often as it occurs there.
     * Imported posts contain such markup, and an edit must not fail on it.
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

    /**
     * The HTML of one node. `body()` creates every document as an
     * `HTMLDocument`; the assert states this for PHPStan.
     */
    private static function html(Node $node): string
    {
        $document = $node->ownerDocument;
        assert($document instanceof HTMLDocument);
        return $document->saveHtml($node);
    }
}
