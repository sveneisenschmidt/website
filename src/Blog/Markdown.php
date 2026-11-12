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
}
