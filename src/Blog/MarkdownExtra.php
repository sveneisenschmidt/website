<?php

namespace Website\Blog;

use ParsedownExtra;

/**
 * Kirby's parser for the Markdown option `extra` (footnotes, definition
 * lists, attributes) with the spoiler section (SpoilerBlock).
 */
final class MarkdownExtra extends ParsedownExtra
{
    use SpoilerBlock;

    public function __construct()
    {
        parent::__construct();
        $this->addSpoilerBlock();
    }
}
