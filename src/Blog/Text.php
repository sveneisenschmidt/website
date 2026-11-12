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
     * Turns each spoiler section of rendered HTML into a div with the class
     * spoiler. In the Markdown, a line (spoiler) starts a section and a line
     * (/spoiler) ends it. Every word of the section gets a span, so the
     * style sheet covers the words and leaves the spaces between them free.
     * spoiler.js uncovers a section on a click.
     *
     * A section holds the elements between its two markers that have the
     * same parent, so the HTML stays well-formed. Without an end marker the
     * section runs to the end of its parent: a forgotten (/spoiler) covers
     * too much and never shows the words. A marker that is no line of its
     * own in a paragraph stays text, as does one in a code block, in a tight
     * list or inside an emphasis.
     *
     * The HTML of a text with a section comes from the HTML parser, which
     * writes <br> for <br /> and each attribute on one line.
     */
    public static function spoilers(string $html): string
    {
        if (!str_contains($html, '(spoiler)')) {
            return $html;
        }
        $document = self::document($html);
        foreach ($document->querySelectorAll('p') as $paragraph) {
            self::markerParagraphs($paragraph);
        }
        $sections = 0;
        foreach ($document->querySelectorAll('p') as $start) {
            // A start marker inside a section is covered text of it.
            if (!self::isMarker($start, '(spoiler)') || $start->closest('.spoiler') !== null) {
                continue;
            }
            $section = $document->createElement('div');
            $section->className = 'spoiler';
            $start->before($section);
            $node = $start->nextSibling;
            while ($node !== null && !self::isMarker($node, '(/spoiler)')) {
                $next = $node->nextSibling;
                $section->append($node);
                $node = $next;
            }
            if ($node instanceof \Dom\Element) {
                $node->remove();
            }
            $start->remove();
            self::cover($section);
            $sections++;
        }
        return $sections === 0 ? $html : $document->body->innerHTML;
    }

    /**
     * Rendered HTML without its spoiler sections. The summary of a page goes
     * into link previews and the feed, where nothing covers the words.
     */
    public static function withoutSpoilers(string $html): string
    {
        if (!str_contains($html, '<div class="spoiler">')) {
            return $html;
        }
        $document = self::document($html);
        foreach ($document->querySelectorAll('div.spoiler') as $section) {
            // The line end after the section goes with it, so the text
            // around it keeps one line end.
            if ($section->nextSibling instanceof \Dom\Text && trim($section->nextSibling->data) === '') {
                $section->nextSibling->remove();
            }
            $section->remove();
        }
        return $document->body->innerHTML;
    }

    /** A piece of HTML as the body of a document. */
    private static function document(string $html): \Dom\HTMLDocument
    {
        return \Dom\HTMLDocument::createFromString('<!DOCTYPE html><body>' . $html . '</body>', LIBXML_NOERROR, 'UTF-8');
    }

    private static function isMarker(\Dom\Node $node, string $marker): bool
    {
        return $node instanceof \Dom\Element && $node->localName === 'p' && trim($node->textContent) === $marker;
    }

    /**
     * Makes each marker line of a paragraph a paragraph of its own. Without
     * a blank line next to a marker, Markdown puts the marker into the
     * paragraph of the neighbour line.
     */
    private static function markerParagraphs(\Dom\Element $paragraph): void
    {
        if (!str_contains($paragraph->textContent, 'spoiler)')) {
            return;
        }
        $document = $paragraph->ownerDocument;
        // The lines of the paragraph, each a list of nodes.
        $lines = [[]];
        foreach (iterator_to_array($paragraph->childNodes) as $child) {
            if (!$child instanceof \Dom\Text) {
                $lines[array_key_last($lines)][] = $child;
                continue;
            }
            foreach (explode("\n", $child->data) as $i => $text) {
                if ($i > 0) {
                    $lines[] = [];
                }
                if ($text !== '') {
                    $lines[array_key_last($lines)][] = $document->createTextNode($text);
                }
            }
        }
        // A marker line holds the marker and nothing else. Two spaces at
        // its end give a <br>.
        $marker = function (array $line): ?string {
            $line = array_values(array_filter($line, fn(\Dom\Node $node) => !($node instanceof \Dom\Element && $node->localName === 'br')));
            $text = count($line) === 1 && $line[0] instanceof \Dom\Text ? trim($line[0]->data) : '';
            return in_array($text, ['(spoiler)', '(/spoiler)'], true) ? $text : null;
        };
        if (array_filter(array_map($marker, $lines)) === []) {
            return;
        }
        $new = null;
        foreach ($lines as $line) {
            $text = $marker($line);
            if ($text !== null) {
                $new = null;
                $own = $document->createElement('p');
                $own->textContent = $text;
                $paragraph->before($own, "\n");
            } elseif ($line !== []) {
                if ($new === null) {
                    $new = $document->createElement('p');
                    $paragraph->before($new, "\n");
                } else {
                    $new->append("\n");
                }
                $new->append(...$line);
            }
        }
        // The line end after the last new paragraph is the one that follows
        // the old paragraph.
        if ($paragraph->previousSibling instanceof \Dom\Text) {
            $paragraph->previousSibling->remove();
        }
        $paragraph->remove();
    }

    /** Puts each word in the text of $node into a span. The content of a script or style element is code and stays. */
    private static function cover(\Dom\Node $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \Dom\Element && !in_array($child->localName, ['script', 'style'], true)) {
                self::cover($child);
            } elseif ($child instanceof \Dom\Text && trim($child->data) !== '') {
                $pieces = [];
                foreach (preg_split('!(\S+)!u', $child->data, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $piece) {
                    if (trim($piece) === '') {
                        $pieces[] = $piece;
                    } else {
                        $pieces[] = $word = $node->ownerDocument->createElement('span');
                        $word->className = 'spoiler-word';
                        $word->textContent = $piece;
                    }
                }
                $child->replaceWith(...$pieces);
            }
        }
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
