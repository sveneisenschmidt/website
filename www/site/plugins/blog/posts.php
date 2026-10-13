<?php

use Kirby\Cms\Page;
use Kirby\Cms\Pages;

/**
 * The posts and what the templates say about them.
 */

/** The sections that hold posts, as in Hugo. */
const BLOG_SECTIONS = ['photos', 'trips', 'notes', 'essays'];

/** Hugo's [params] from hugo.toml. */
const BLOG_SITE = [
    'description' => 'Personal website of Sven Eisenschmidt, based in Leipzig, Germany.',
    'heading' => 'Sven Eisenschmidt',
    'author' => 'Sven Eisenschmidt',
    'keywords' => 'Sven Eisenschmidt, Leipzig, Germany, video games, music, cycling, travel, software development, photography',
    'jobTitle' => 'Chief Technology & Product Officer',
    'employer' => 'roadsurfer',
    'employerUrl' => 'https://roadsurfer.com',
    'githubUrl' => 'https://github.com/sveneisenschmidt',
    'linkedinUrl' => 'https://linkedin.com/in/sveneisenschmidt',
    'stravaUrl' => 'https://strava.com/athletes/sveneisenschmidt',
    'email' => 'sven.eisenschmidt@gmail.com',
];

/** All published posts, newest first. Hugo sorted by date, then by title. */
function blog_posts(): Pages
{
    $posts = new Pages([]);
    foreach (BLOG_SECTIONS as $section) {
        if ($parent = page($section)) {
            $posts->add($parent->children()->listed());
        }
    }
    return $posts->sortBy('date', 'desc', 'title', 'asc');
}

/** True for a post (photo, trip, note or essay), also a draft. */
function blog_is_post(Page $page): bool
{
    return in_array($page->parent()?->id(), BLOG_SECTIONS, true) && $page->parent()->parent() === null;
}

/** The picks with a cover, newest first (front.html). */
function blog_picks(): Pages
{
    return blog_posts()->filter(fn (Page $post) => $post->pick()->toBool() && $post->cover()->toFile() !== null);
}

/** "January 2, 2006" */
function blog_date(Page $post): string
{
    return $post->date()->toDate('F j, Y');
}

/**
 * The type line of a post (article-type.html): the day of a trip, the
 * reading time of an essay, else the type. Without $withType, as on the
 * post page, no type word.
 */
function blog_type_line(Page $post, bool $withType = true): string
{
    if ($post->trip()->isNotEmpty()) {
        $trip = $post->trip()->value();
        $days = blog_posts()->filter(fn (Page $p) => $p->trip()->value() === $trip)->sortBy('date', 'asc');
        return sprintf('%s, day %d of %d', ucwords(str_replace('-', ' ', $trip)), $days->indexOf($post) + 1, $days->count());
    }
    return match ($post->intendedTemplate()->name()) {
        // Hugo's .ReadingTime: 213 words a minute, rounded up.
        'essay' => ($withType ? 'Essay, ' : '') . (int)ceil(blog_words(blog_content($post)) / 213) . ' min',
        'note' => $withType ? 'Note' : '',
        'photo' => $withType ? 'Photo' : '',
        default => '',
    };
}

/** True when the text of a post holds the map. */
function blog_has_map(Page $page): bool
{
    return str_contains($page->intro()->value() . $page->text()->value(), '(map:');
}

/**
 * Posts that hold every word of $query in title, intro, text or the alt
 * text of an image, newest first, at most 40. KirbyText tags and link
 * targets do not count: a file name or URL would match almost anything.
 */
function blog_search(string $query): Pages
{
    $words = preg_split('/\s+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY);
    if ($words === []) {
        return new Pages([]);
    }
    return blog_posts()->filter(function (Page $post) use ($words) {
        $text = $post->title()->value() . ' ' . $post->intro()->value() . ' ' . $post->text()->value();
        $text = preg_replace(['/\([a-z]+:[^)]*\)/', '/\]\([^)]*\)/'], [' ', ']'], $text);
        $text = mb_strtolower($text . ' ' . implode(' ', $post->images()->pluck('alt')));
        foreach ($words as $word) {
            if (!str_contains($text, $word)) {
                return false;
            }
        }
        return true;
    })->limit(40);
}
