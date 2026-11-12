<?php

namespace Website\Blog;

/**
 * The spoiler section as a block of the Markdown parser. A line (spoiler)
 * starts the section and a line (/spoiler) ends it, as ">" marks the lines
 * of a quote. The section becomes a div with the class spoiler, and its
 * lines are Markdown: paragraphs, headings, lists, images.
 *
 * The parser decides what a marker line is, by the rules it has for every
 * block. A start marker ends the paragraph, list, quote or HTML block above
 * it without a blank line. A marker in a code block is code, and a marker
 * inside a sentence is text. Without an end marker the section runs to the
 * end of the text, or to the end of the quote or list item that holds it:
 * a forgotten (/spoiler) covers too much and never shows the words.
 *
 * The first (/spoiler) line ends the section, also when the section shows
 * it in a code block of its own.
 *
 * Kirby has two parser classes, Parsedown and ParsedownExtra, and selects
 * one with the option `extra`. Markdown and MarkdownExtra extend them and
 * use this trait, so both know the section.
 */
trait SpoilerBlock
{
    /** Registers the block: the parser tries it for a line that starts with "(". */
    private function addSpoilerBlock(): void
    {
        $this->BlockTypes['('][] = 'Spoiler';
    }

    /**
     * @param array{body: string, indent: int, text: string} $Line
     * @return array<string, mixed>|null
     */
    protected function blockSpoiler(array $Line): ?array
    {
        if (!self::isMarker($Line, '(spoiler)')) {
            return null;
        }
        return [
            'element' => [
                'name' => 'div',
                'attributes' => ['class' => 'spoiler'],
                'handler' => ['function' => 'linesElements', 'argument' => [], 'destination' => 'elements'],
            ],
        ];
    }

    /**
     * @param array{body: string, indent: int, text: string} $Line
     * @param array<string, mixed> $Block
     * @return array<string, mixed>|null
     */
    protected function blockSpoilerContinue(array $Line, array $Block): ?array
    {
        if (isset($Block['complete'])) {
            return null;
        }
        if (self::isMarker($Line, '(/spoiler)')) {
            $Block['complete'] = true;
            return $Block;
        }
        // The parser skips blank lines and counts them in `interrupted`.
        // The section needs them back: they separate its paragraphs.
        $blank = array_fill(0, $Block['interrupted'] ?? 0, '');
        unset($Block['interrupted']);
        $Block['element']['handler']['argument'] = [...$Block['element']['handler']['argument'], ...$blank, $Line['body']];
        return $Block;
    }

    /**
     * A block of HTML, such as the map of (map:), takes every line up to
     * the next blank line. A start marker ends it, so the section below a
     * map needs no blank line.
     *
     * @param array{body: string, indent: int, text: string} $Line
     * @param array<string, mixed> $Block
     * @return array<string, mixed>|null
     */
    protected function blockMarkupContinue($Line, array $Block)
    {
        return self::isMarker($Line, '(spoiler)') ? null : parent::blockMarkupContinue($Line, $Block);
    }

    /**
     * A quote takes a line without ">" below it as more text of its last
     * paragraph. A start marker ends the quote. A marker of the quote
     * itself is a line "> (spoiler)".
     *
     * @param array{body: string, indent: int, text: string} $Line
     * @param array<string, mixed> $Block
     * @return array<string, mixed>|null
     */
    protected function blockQuoteContinue($Line, array $Block)
    {
        return self::isMarker($Line, '(spoiler)') ? null : parent::blockQuoteContinue($Line, $Block);
    }

    /**
     * A list takes a line below it as more text of its last item. A start
     * marker ends the list, unless it is indented to the text of the item:
     * then the section is in the item.
     *
     * @param array{body: string, indent: int, text: string} $Line
     * @param array<string, mixed> $Block
     * @return array<string, mixed>|null
     */
    protected function blockListContinue($Line, array $Block)
    {
        $inItem = $Line['indent'] >= $Block['indent'] + strlen($Block['data']['marker']);
        return !$inItem && self::isMarker($Line, '(spoiler)') ? null : parent::blockListContinue($Line, $Block);
    }

    /**
     * A marker line holds the marker and nothing else. Indented by four or
     * more spaces, the line is code.
     *
     * @param array{body: string, indent: int, text: string} $Line
     */
    private static function isMarker(array $Line, string $marker): bool
    {
        return $Line['indent'] < 4 && rtrim($Line['text']) === $marker;
    }
}
