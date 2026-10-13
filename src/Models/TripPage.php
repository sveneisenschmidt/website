<?php

namespace Website\Models;

use Kirby\Cms\Pages;
use Kirby\Content\Field;
use Website\Blog\Posts;

/**
 * The page model of a trip post. A trip post describes one day of a
 * journey. The field `trip` names the journey, and the trip posts with the
 * same name are its days.
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

    /**
     * "Summer 2026, day 5 of 22". The journey name is the field `trip` with
     * "-" as a space and every word capitalised. Without a journey the type
     * line is empty.
     */
    public function typeLine(bool $withType = true): string
    {
        if ($this->trip()->isEmpty()) {
            return parent::typeLine($withType);
        }
        $days = $this->days();
        return sprintf('%s, day %d of %d', ucwords(str_replace('-', ' ', $this->trip()->value())), $days->indexOf($this) + 1, $days->count());
    }
}
