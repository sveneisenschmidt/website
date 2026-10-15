<?php

namespace Website\Models;

use Kirby\Content\Field;
use Kirby\Content\VersionId;
use Website\Blog\Images;
use Website\Blog\Posts;

/**
 * The page model of a post (photo, trip, note or essay, see
 * Website\Blog\Types), published or draft. plugins/blog registers it for
 * photo and note; trips and essays use the subclasses TripPage and
 * EssayPage. A post's URL is /YYYY-MM-DD-slug/. After a change of slug or
 * date, Redirects::moved() adds a redirect from the old URL.
 *
 * @method Field date()
 * @method Field pick()
 */
class PostPage extends SitePage
{
    /** @param array<string, mixed>|string|null $options With options, Kirby's own URL (for a language or URL parameters) */
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

    /**
     * Removes the post from the page cache, so the next request renders it
     * again. Website\Blog\Likes calls it after a like, because the post shows
     * the count. Kirby empties the whole cache only after a content change.
     */
    public function uncache(): void
    {
        $this->kirby()->cache('pages')->remove($this->cacheId('html', VersionId::latest()));
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
     * The type line: the short text after the date in the timeline and on
     * the post page. For a note or a photo it is the type. Without
     * $withType, as on the post page, it is empty, because the label above
     * the title names the type there. TripPage and EssayPage return the day
     * of the journey and the reading time.
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
     * The posts beside this one as [post, label]: the newer post labelled
     * Next, the older one labelled Previous. The collection is sorted newest
     * first, so prev() returns the newer post.
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
     * The <img> attributes of the cover at the full column width
     * (Images::picture()), or null without a cover. The front page shows the
     * newest featured post this way.
     *
     * @return array{src: string, srcset: string, width: int, height: int}|null
     */
    public function coverPicture(): ?array
    {
        $cover = $this->coverImage();
        return $cover === null ? null : Images::picture($cover);
    }

    /**
     * The view-transition-name of the cover. The tile on the front page and
     * the cover in the post use the same name, so the browser animates the
     * image from one page to the other. Characters that the name does not
     * allow become "-".
     */
    public function transitionName(): string
    {
        return 'post-' . preg_replace('/[^a-zA-Z0-9-]/', '-', trim((string) parse_url($this->url(), PHP_URL_PATH), '/'));
    }
}
