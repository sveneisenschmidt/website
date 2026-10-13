<?php

namespace Website\Blog;

use Kirby\Cms\Page;
use Kirby\Toolkit\Str;

/**
 * Text from Markdown: headings with an id and an anchor, the text a reader
 * reads and its words. The models (SitePage) build the content and the
 * summary of a page from these.
 */
final class Text
{
    /**
     * KirbyText, with an id and an anchor on every heading. Links point at
     * these ids: they stay as they are.
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

    /** The id of a heading: letters, digits and "_" in lower case, space and "-" as "-". */
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
     * Markdown without its (image:) and (map:) tags, for plain text only. A
     * figure or a map has no words a reader reads, and each figure would cost
     * three thumbnails, a file lookup and its EXIF. Nested brackets as in
     * Kirby's own tag pattern.
     */
    public static function withoutMedia(string $markdown): string
    {
        return preg_replace('!(?=\((?:image|map):)(\((?:[^()]+|(?1))*+\))!i', '', $markdown);
    }

    /**
     * Rendered HTML as the text a reader reads: no tags, entities decoded,
     * without the EXIF rows of figures and the scripts of maps.
     */
    public static function plain(string $html): string
    {
        return trim(Str::unhtml(preg_replace(['!<dl class="exif">.*?</dl>!s', '!<script\b.*?</script>!s'], '', $html)));
    }

    /** The words of a plain text. */
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
