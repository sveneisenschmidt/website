<?php

use Kirby\Cms\Page;
use Kirby\Toolkit\Str;

/**
 * Text from Markdown: headings with an id and an anchor, the content and
 * the summary of a page, the text a reader reads and its words.
 */

/**
 * KirbyText, with an id and an anchor on every heading. Links point at
 * these ids: they stay as they are.
 */
function blog_html(string $text, Page $page): string
{
    $ids = [];
    return preg_replace_callback('!<h([1-6])>(.*?)</h\1>!s', function (array $m) use (&$ids) {
        $id = blog_anchor(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        // A repeated id gets a number: x, x-1, x-2.
        $n = $ids[$id] = isset($ids[$id]) ? $ids[$id] + 1 : 0;
        $id = $n === 0 ? $id : "$id-$n";
        return "<h{$m[1]} id=\"$id\">{$m[2]}<a class=\"heading-anchor\" href=\"#$id\">#</a>\n</h{$m[1]}>";
    }, kirby()->kirbytext($text, ['parent' => $page]));
}

/** The id of a heading: letters, digits and "_" in lower case, space and "-" as "-". */
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

/** Intro and text as one Markdown text. */
function blog_content(Page $page): string
{
    return blog_html(trim($page->intro()->value() . "\n\n" . $page->text()->value()), $page);
}

/** The summary as plain text: the intro, without one the text. */
function blog_summary(Page $page): string
{
    return blog_plain($page->intro()->or($page->text())->kirbytext()->value());
}

/**
 * Rendered HTML as the text a reader reads: no tags, entities decoded,
 * without the EXIF rows of figures and the scripts of maps.
 */
function blog_plain(string $html): string
{
    return trim(Str::unhtml(preg_replace(['!<dl class="exif">.*?</dl>!s', '!<script\b.*?</script>!s'], '', $html)));
}

/** The words of a plain text. */
function blog_words(string $text): int
{
    return count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY));
}
