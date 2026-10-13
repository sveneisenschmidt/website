<?php

namespace Website\Models;

use Kirby\Cms\Pages;
use Kirby\Content\Field;
use Website\Blog\Posts;

/**
 * A trip: one day of a journey. The field trip names the journey; its
 * posts are the days.
 *
 * @method Field trip()
 */
class TripPage extends PostPage
{
    /**
     * The published posts of the same journey, the first day first.
     *
     * @return Pages<PostPage>
     */
    public function days(): Pages
    {
        $trip = $this->trip()->value();
        return Posts::all()->filter(fn(PostPage $post) => $post instanceof self && $post->trip()->value() === $trip)->sortBy('date', 'asc');
    }

    /** "Summer 2026, day 5 of 22"; without a journey no type line. */
    public function typeLine(bool $withType = true): string
    {
        if ($this->trip()->isEmpty()) {
            return parent::typeLine($withType);
        }
        $days = $this->days();
        return sprintf('%s, day %d of %d', ucwords(str_replace('-', ' ', $this->trip()->value())), $days->indexOf($this) + 1, $days->count());
    }
}
