<?php

namespace Website\Blog;

use Kirby\Cms\Page;
use Kirby\Toolkit\Str;

/**
 * Turns the Markdown of a page into HTML or into plain text. SitePage uses
 * it for the body, the summary and the word count of a page.
 */
final class Text
{
    /**
     * Renders KirbyText and gives every heading an id and an anchor link "#".
     * Links from other pages point at these ids, so anchor() must keep
     * producing the same ids for the same headings.
     */
    public static function html(string $text, Page $page): string
    {
        $ids = [];
        return preg_replace_callback('!<h([1-6])>(.*?)</h\1>!s', function (array $m) use (&$ids) {
            $id = self::anchor(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            // A repeated id gets a number: x, x-1, x-2.
            $n = $ids[$id] = isset($ids[$id]) ? $ids[$id] + 1 : 0;
            $id = $n === 0 ? $id : "$id-$n";
            return "<h{$m[1]} id=\"$id\">{$m[2]}<a class=\"heading-anchor\" href=\"#$id\">#</a>\n</h{$m[1]}>";
        }, kirby()->kirbytext($text, ['parent' => $page]));
    }

    /** The id of a heading: letters, digits and "_" in lower case, each space or "-" as "-", every other character dropped. */
    public static function anchor(string $text): string
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

    /**
     * Removes the (image:) and (map:) tags from Markdown that is rendered as
     * plain text. A figure or a map adds no words to read. Rendering a figure
     * would also create three thumbnails, look up the file and read its EXIF.
     * The pattern allows nested parentheses inside a tag, as Kirby's own tag
     * pattern does.
     */
    public static function withoutMedia(string $markdown): string
    {
        return preg_replace('!(?=\((?:image|map):)(\((?:[^()]+|(?1))*+\))!i', '', $markdown);
    }

    /**
     * Rendered HTML as plain text: tags removed, entities decoded. The EXIF
     * lines of figures and the route scripts of maps are removed first,
     * because they are not text that a reader reads.
     */
    public static function plain(string $html): string
    {
        return trim(Str::unhtml(preg_replace(['!<dl class="exif">.*?</dl>!s', '!<script\b.*?</script>!s'], '', $html)));
    }

    /** The number of words in a plain text, split at whitespace. */
    public static function words(string $text): int
    {
        return count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY));
    }

    /** A number with at most $decimals decimals, without trailing zeros: 2.80 -> "2.8", 45.0 -> "45". */
    public static function number(float $value, int $decimals = 1): string
    {
        return rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.');
    }
}
