<?php

namespace Website\Blog;

use ParsedownExtra;

/**
 * Kirby's parser for the Markdown option `extra` (footnotes, definition
 * lists, attributes) with the spoiler section (SpoilerBlock).
 *
 * This parser ends a block of HTML at its closing tag, so the HTML of a map
 * above a start marker needs no rule here, and a section inside
 * <div markdown="1"> stays inside the div. HTML without its closing tag
 * takes the rest of the text, markers included.
 *
 * The text of a footnote stands in the footnote list at the end of the
 * page. A footnote that a section defines shows its text there, uncovered.
 */
class MarkdownExtra extends ParsedownExtra
{
    use SpoilerBlock;

    public function __construct()
    {
        parent::__construct();
        $this->addSpoilerBlock();
    }

    /**
     * A definition takes a line below it as more of its text. A start
     * marker ends the definition list.
     *
     * @param array{body: string, indent: int, text: string} $Line
     * @param array<string, mixed> $Block
     * @return array<string, mixed>|null
     */
    protected function blockDefinitionListContinue($Line, array $Block)
    {
        return self::isMarker($Line, '(spoiler)') ? null : parent::blockDefinitionListContinue($Line, $Block);
    }

    /**
     * A footnote takes a line below it as more of its text. A start marker
     * ends the footnote.
     *
     * @param array{body: string, indent: int, text: string} $Line
     * @param array<string, mixed> $Block
     * @return array<string, mixed>|null
     */
    protected function blockFootnoteContinue($Line, $Block)
    {
        return self::isMarker($Line, '(spoiler)') ? null : parent::blockFootnoteContinue($Line, $Block);
    }
}
