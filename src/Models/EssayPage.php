<?php

namespace Website\Models;

/**
 * An essay: its type line says how long it takes to read.
 */
class EssayPage extends PostPage
{
    /** Minutes to read: 213 words a minute, rounded up. */
    public function readingTime(): int
    {
        return (int) ceil($this->wordCount() / 213);
    }

    /** "Essay, 4 min", on the post page only "4 min". */
    public function typeLine(bool $withType = true): string
    {
        return ($withType ? 'Essay, ' : '') . $this->readingTime() . ' min';
    }
}
