<?php

namespace Website\Blog;

use Parsedown;

/** Kirby's Markdown parser with the spoiler section (SpoilerBlock). */
final class Markdown extends Parsedown
{
    use SpoilerBlock;

    public function __construct()
    {
        $this->addSpoilerBlock();
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
}
