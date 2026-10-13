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
 * Every page of the site, the default page model. Its URL ends with a
 * slash, the home page is at /. Links and feed readers know these URLs:
 * they stay as they are. A post has its own model (PostPage).
 *
 * @method Field intro()
 * @method Field text()
 * @method Field cover()
 * @method Field lastmod()
 */
class SitePage extends Page
{
    /** @param array<string, mixed>|string|null $options Kirby's own URL, for a language or a parameter */
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

    /** True for a post or About: the head calls it an article. */
    public function isArticle(): bool
    {
        return $this->intendedTemplate()->name() === 'about';
    }

    /** Intro and text as HTML, every heading with an id and an anchor. */
    public function bodyHtml(): string
    {
        return Text::html(trim($this->intro()->value() . "\n\n" . $this->text()->value()), $this);
    }

    /** The summary as plain text: the intro, without one the text. */
    public function summary(): string
    {
        $field = $this->intro()->or($this->text());
        return Text::plain($field->value(Text::withoutMedia($field->value()))->kirbytext()->value());
    }

    /** Intro and text as plain text, for counting words. Heading anchors are no words. */
    public function plainText(): string
    {
        $text = trim($this->intro()->value() . "\n\n" . $this->text()->value());
        return Text::plain(kirby()->kirbytext(Text::withoutMedia($text), ['parent' => $this]));
    }

    /** The words of intro and text. */
    public function wordCount(): int
    {
        return Text::words($this->plainText());
    }

    /** The file name in the first (image:) of intro and text, or null without one. */
    public function firstImageName(): ?string
    {
        return preg_match('!\(image:\s*([^\s)]+)!i', $this->intro()->value() . "\n" . $this->text()->value(), $m) ? $m[1] : null;
    }

    /** True when the text holds a map: the head then loads Leaflet. */
    public function hasMap(): bool
    {
        return str_contains($this->intro()->value() . $this->text()->value(), '(map:');
    }

    /** When the page last changed: About's date by hand, else the time of the page's content file. */
    public function lastModified(): int
    {
        return $this->lastmod()->isNotEmpty() ? $this->lastmod()->toDate() : $this->modified();
    }

    /** The meta description: the summary of a post or of About, cut after a word at 160 characters, else the site's. */
    public function metaDescription(): string
    {
        $summary = $this->isArticle() ? $this->summary() : '';
        return $summary === '' ? Site::DESCRIPTION : Str::excerpt($summary, 160, false);
    }

    /** The cover image, or null. */
    public function coverImage(): ?File
    {
        return $this->cover()->toFile();
    }

    /** The picture for link previews: the cover, on the home page that of the featured photo. */
    public function shareCover(): ?File
    {
        return $this->isHomePage() ? Posts::picks()->first()?->coverImage() : $this->coverImage();
    }

    /** The link preview picture, 1200 x 630 px, or null. */
    public function shareImage(): ?string
    {
        return $this->shareCover()?->thumb(['width' => 1200, 'height' => 630, 'crop' => true, 'format' => 'webp', 'quality' => 80])->url();
    }
}
