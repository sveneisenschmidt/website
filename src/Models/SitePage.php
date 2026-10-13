<?php

namespace Website\Models;

use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Content\Field;
use Kirby\Toolkit\Str;
use Website\Blog\Posts;
use Website\Blog\Site;
use Website\Blog\Text;

/**
 * The default page model, for every page that is not a post (posts use
 * PostPage). Its URL ends with a slash, and the home page is at /. These
 * URLs must not change, because links on other sites and feed readers use
 * them.
 *
 * @method Field intro()
 * @method Field text()
 * @method Field cover()
 * @method Field lastmod()
 */
class SitePage extends Page
{
    /** @param array<string, mixed>|string|null $options With options, Kirby's own URL (for a language or URL parameters) */
    public function url($options = null): string
    {
        if ($options !== null) {
            return parent::url($options);
        }
        $base = $this->kirby()->url('base');
        if ($this->isHomePage()) {
            return $base . '/';
        }
        return $base . '/' . $this->uri() . '/';
    }

    /**
     * True for About; PostPage returns true for every post. An article gets
     * og:type "article" and the article meta tags in snippets/head.php.
     */
    public function isArticle(): bool
    {
        return $this->intendedTemplate()->name() === 'about';
    }

    /** Intro and text as HTML, every heading with an id and an anchor. */
    public function bodyHtml(): string
    {
        return Text::html(trim($this->intro()->value() . "\n\n" . $this->text()->value()), $this);
    }

    /** The summary as plain text: the intro, or the text when the intro is empty. */
    public function summary(): string
    {
        $field = $this->intro()->or($this->text());
        return Text::plain($field->value(Text::withoutMedia($field->value()))->kirbytext()->value());
    }

    /**
     * Intro and text as plain text, for counting words. It renders with
     * kirbytext() and not with Text::html(), so the "#" anchors of headings
     * do not count as words.
     */
    public function plainText(): string
    {
        $text = trim($this->intro()->value() . "\n\n" . $this->text()->value());
        return Text::plain(kirby()->kirbytext(Text::withoutMedia($text), ['parent' => $this]));
    }

    /** The number of words in intro and text. */
    public function wordCount(): int
    {
        return Text::words($this->plainText());
    }

    /** The file name in the first (image:) of intro and text, or null without one. */
    public function firstImageName(): ?string
    {
        return preg_match('!\(image:\s*([^\s)]+)!i', $this->intro()->value() . "\n" . $this->text()->value(), $m) ? $m[1] : null;
    }

    /** True when intro or text contain a (map:) tag. snippets/head.php then loads Leaflet, the map library, and map.js. */
    public function hasMap(): bool
    {
        return str_contains($this->intro()->value() . $this->text()->value(), '(map:');
    }

    /**
     * The time of the last change as a Unix timestamp. The field `lastmod`
     * (only About has it, Panel: "Zuletzt geprüft") wins over the
     * modification time of the content file.
     */
    public function lastModified(): int
    {
        return $this->lastmod()->isNotEmpty() ? $this->lastmod()->toDate() : $this->modified();
    }

    /**
     * The meta description: for a post or About, its summary, shortened at
     * a word to about 160 characters; for every other page, Site::DESCRIPTION.
     */
    public function metaDescription(): string
    {
        $summary = $this->isArticle() ? $this->summary() : '';
        return $summary === '' ? Site::DESCRIPTION : Str::excerpt($summary, 160, false);
    }

    public function coverImage(): ?File
    {
        return $this->cover()->toFile();
    }

    /** The image for link previews: the page's cover; on the home page, the cover of the newest featured post. */
    public function shareCover(): ?File
    {
        return $this->isHomePage() ? Posts::picks()->first()?->coverImage() : $this->coverImage();
    }

    /** The URL of the link preview image, cropped to 1200 x 630 px, the size that Facebook recommends for og:image; or null. */
    public function shareImage(): ?string
    {
        return $this->shareCover()?->thumb(['width' => 1200, 'height' => 630, 'crop' => true, 'format' => 'webp', 'quality' => 80])->url();
    }
}
