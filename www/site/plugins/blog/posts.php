<?php

use Kirby\Cms\Page;
use Kirby\Cms\Pages;

/**
 * The posts and what the templates say about them.
 */

/**
 * The four post types: template => the section that holds them, the name
 * of one post (Panel, assistant, Verlauf), the menu entry in the Panel
 * (label and icon) and whether a post has a map.
 */
const BLOG_TYPES = [
    'photo' => ['section' => 'photos', 'label' => 'Foto', 'menu' => 'Fotos', 'icon' => 'image', 'map' => true],
    'trip' => ['section' => 'trips', 'label' => 'Reise', 'menu' => 'Reisen', 'icon' => 'map', 'map' => true],
    'note' => ['section' => 'notes', 'label' => 'Notiz', 'menu' => 'Notizen', 'icon' => 'text', 'map' => false],
    'essay' => ['section' => 'essays', 'label' => 'Essay', 'menu' => 'Essays', 'icon' => 'book', 'map' => false],
];

/** The sections that hold posts. */
function blog_sections(): array
{
    return array_column(BLOG_TYPES, 'section');
}

/** True for a post type that can have a map (photo, trip). */
function blog_type_has_map(string $template): bool
{
    return BLOG_TYPES[$template]['map'] ?? false;
}

/** The site and its author, for the frame, meta tags and JSON-LD. */
const BLOG_SITE = [
    'description' => 'Personal website of Sven Eisenschmidt, based in Leipzig, Germany.',
    'heading' => 'Sven Eisenschmidt',
    'author' => 'Sven Eisenschmidt',
    'keywords' => 'Sven Eisenschmidt, Leipzig, Germany, video games, music, cycling, travel, software development, photography',
    'githubUrl' => 'https://github.com/sveneisenschmidt',
    'linkedinUrl' => 'https://linkedin.com/in/sveneisenschmidt',
    'stravaUrl' => 'https://strava.com/athletes/sveneisenschmidt',
    'email' => 'sven.eisenschmidt@gmail.com',
];

/** True for a post (photo, trip, note or essay), also a draft. */
function blog_is_post(Page $page): bool
{
    return in_array($page->parent()?->id(), blog_sections(), true) && $page->parent()->parent() === null;
}

/** The picks with a cover, newest first: the front page shows them. */
function blog_picks(): Pages
{
    return collection('posts')->filter(fn(Page $post) => $post->pick()->toBool() && $post->cover()->toFile() !== null);
}

/**
 * The timeline beside a page, as entries [post, label]: beside a post its
 * two neighbours, Next and Previous; beside any other page the newest
 * $limit posts, the first one Latest. neighbours says which of the two.
 */
function blog_timeline(?Page $current, int $limit): array
{
    $posts = collection('posts');
    $entries = [];
    if ($current !== null && $posts->has($current->id())) {
        if ($next = $current->prev($posts)) {
            $entries[] = [$next, 'Next'];
        }
        if ($previous = $current->next($posts)) {
            $entries[] = [$previous, 'Previous'];
        }
        return ['neighbours' => true, 'entries' => $entries];
    }
    foreach ($posts->limit($limit) as $post) {
        $entries[] = [$post, $entries === [] ? 'Latest' : ''];
    }
    return ['neighbours' => false, 'entries' => $entries];
}

/** Posts by month in their order, "October 2026" => [post, ...]. */
function blog_months(Pages $posts): array
{
    $months = [];
    foreach ($posts as $post) {
        $months[$post->date()->toDate('F Y')][] = $post;
    }
    return $months;
}

/** "January 2, 2006" */
function blog_date(Page $post): string
{
    return $post->date()->toDate('F j, Y');
}

/**
 * The type line of a post: the day of a trip, the reading time of an
 * essay, else the type. Without $withType, as on the post page, no type
 * word.
 */
function blog_type_line(Page $post, bool $withType = true): string
{
    if ($post->trip()->isNotEmpty()) {
        $trip = $post->trip()->value();
        $days = collection('posts')->filter(fn(Page $p) => $p->trip()->value() === $trip)->sortBy('date', 'asc');
        return sprintf('%s, day %d of %d', ucwords(str_replace('-', ' ', $trip)), $days->indexOf($post) + 1, $days->count());
    }
    return match ($post->intendedTemplate()->name()) {
        // Reading time: 213 words a minute, rounded up.
        'essay' => ($withType ? 'Essay, ' : '') . (int) ceil(blog_words(blog_plain_content($post)) / 213) . ' min',
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
    return collection('posts')->filter(function (Page $post) use ($words) {
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
