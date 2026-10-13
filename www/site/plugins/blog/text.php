<?php

use Kirby\Cms\Page;

/**
 * Text as Hugo made it from Markdown: headings with an anchor, the summary,
 * plain text, words and truncation for meta tags and the feed. Each
 * function does what the Hugo function in its comment did.
 */

/** KirbyText, with ids and anchors on headings as layouts/_markup/render-heading.html. */
function blog_html(string $text, Page $page): string
{
    $ids = [];
    return preg_replace_callback('!<h([1-6])>(.*?)</h\1>!s', function (array $m) use (&$ids) {
        $id = blog_anchor(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        // Hugo numbers a repeated id: x, x-1, x-2.
        $n = $ids[$id] = isset($ids[$id]) ? $ids[$id] + 1 : 0;
        $id = $n === 0 ? $id : "$id-$n";
        return "<h{$m[1]} id=\"$id\">{$m[2]}<a class=\"heading-anchor\" href=\"#$id\">#</a>\n</h{$m[1]}>";
    }, kirby()->kirbytext($text, ['parent' => $page]));
}

/** Hugo's GitHub-style heading id: letters, digits and "_" in lower case, space and "-" as "-". */
function blog_anchor(string $text): string
{
    $id = '';
    foreach (mb_str_split(trim($text)) as $char) {
        if ($char === ' ' || $char === '-') {
            $id .= '-';
        } elseif ($char === '_' || preg_match('/^[\p{L}\p{N}]$/u', $char)) {
            $id .= mb_strtolower($char);
        }
    }
    return $id;
}

/** Hugo's .Content: intro and text as one Markdown text. */
function blog_content(Page $page): string
{
    return blog_html(trim($page->intro()->value() . "\n\n" . $page->text()->value()), $page);
}

/**
 * Hugo's .Summary: the intro. Without one, as Hugo's automatic summary,
 * the paragraphs of the text until they hold 70 words.
 */
function blog_summary(Page $page): string
{
    if ($page->intro()->isNotEmpty()) {
        return blog_html($page->intro()->value(), $page);
    }
    $summary = '';
    foreach (preg_split('/\n\s*\n/', trim((string)$page->text()->value())) as $block) {
        $summary .= ($summary === '' ? '' : "\n\n") . $block;
        if (blog_words(blog_html($summary, $page)) >= 70) {
            break;
        }
    }
    return blog_html($summary, $page);
}

/**
 * Hugo's plainify: tags out, scripts with their content, a line break for
 * each paragraph, a run of white space as its first character. Entities
 * stay, as in Hugo.
 */
function blog_plain(string $html): string
{
    $html = preg_replace('!<script\b.*?</script>!s', '', $html);
    $html = str_replace(["\n", '</p>', '<br>', '<br />'], [' ', '___hugonl_', '___hugonl_', '___hugonl_'], $html);
    $text = str_replace('___hugonl_', "\n", strip_tags($html));
    return preg_replace('/(\s)\s+/u', '$1', $text);
}

/** Hugo's countwords: the words of the plain text. */
function blog_words(string $html): int
{
    return count(preg_split('/\s+/u', blog_plain($html), -1, PREG_SPLIT_NO_EMPTY));
}

/** Hugo's truncate: at most $length characters, cut after a word, then " …". */
function blog_truncate(string $text, int $length = 160): string
{
    if (mb_strlen($text) <= $length) {
        return $text;
    }
    $cut = mb_substr($text, 0, $length + 1);
    return (preg_match('/^(.*\S)\s/us', $cut, $m) ? $m[1] : mb_substr($text, 0, $length)) . ' …';
}
