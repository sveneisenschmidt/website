<?php

namespace Website\Models;

use Kirby\Content\Field;
use Website\Blog\Images;
use Website\Blog\Posts;

/**
 * A post: photo, trip, note or essay (Website\Blog\Types), also a draft.
 * The plugin registers this model for the four templates; trips and essays
 * have their own (TripPage, EssayPage). A post's URL is /YYYY-MM-DD-slug/.
 * A new slug or date leaves a redirect from the old one (Redirects::moved).
 *
 * @method Field date()
 * @method Field pick()
 */
class PostPage extends SitePage
{
    /** @param array<string, mixed>|string|null $options Kirby's own URL, for a language or a parameter */
    public function url($options = null): string
    {
        if ($options !== null) {
            return parent::url($options);
        }
        return $this->kirby()->url('base') . $this->postPath();
    }

    /** The path of the post, /YYYY-MM-DD-slug/, with another slug if given. */
    public function postPath(?string $slug = null): string
    {
        return '/' . $this->date()->toDate('Y-m-d') . '-' . ($slug ?? $this->slug()) . '/';
    }

    public function isArticle(): bool
    {
        return true;
    }

    /** "January 2, 2006" */
    public function dateText(): string
    {
        return $this->date()->toDate('F j, Y');
    }

    /**
     * The type line under the title: the type, or nothing without $withType,
     * as on the post page. A trip names its day, an essay its reading time.
     */
    public function typeLine(bool $withType = true): string
    {
        return match ($this->intendedTemplate()->name()) {
            'note' => $withType ? 'Note' : '',
            'photo' => $withType ? 'Photo' : '',
            default => '',
        };
    }

    /**
     * The neighbours in the timeline as [post, label]: the newer post Next,
     * the older one Previous.
     *
     * @return list<array{PostPage, string}>
     */
    public function neighbours(): array
    {
        $posts = Posts::all();
        $entries = [];
        if ($next = $this->prev($posts)) {
            $entries[] = [$next, 'Next'];
        }
        if ($previous = $this->next($posts)) {
            $entries[] = [$previous, 'Previous'];
        }
        return $entries;
    }

    /**
     * The cover across the column, as the front page shows the newest pick
     * (Images::picture), or null without a cover.
     *
     * @return array{src: string, srcset: string, width: int, height: int}|null
     */
    public function coverPicture(): ?array
    {
        $cover = $this->coverImage();
        return $cover === null ? null : Images::picture($cover);
    }

    /** The name under which the cover moves between pages (view transition). */
    public function transitionName(): string
    {
        return 'post-' . preg_replace('/[^a-zA-Z0-9-]/', '-', trim((string) parse_url($this->url(), PHP_URL_PATH), '/'));
    }
}
