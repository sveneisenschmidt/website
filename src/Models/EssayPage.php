<?php

namespace Website\Models;

/**
 * The page model of an essay. Its type line (see PostPage::typeLine())
 * shows the reading time.
 */
class EssayPage extends PostPage
{
    /** Minutes to read: 213 words a minute, rounded up. */
    public function readingTime(): int
    {
        return (int) ceil($this->wordCount() / 213);
    }

    /** "Essay, 4 min"; without $withType, as on the post page, "4 min". */
    public function typeLine(bool $withType = true): string
    {
        return ($withType ? 'Essay, ' : '') . $this->readingTime() . ' min';
    }
}
