<?php

namespace Website\Blog;

use Kirby\Cms\Page;
use Kirby\Text\KirbyTag;

/**
 * The KirbyText tags of a post: (image:) draws a figure (snippets/figure.php),
 * (map:) the map of a trip. Both stay one HTML block without blank lines,
 * so Markdown leaves them as they are.
 */
final class Tags
{
    /** The (image:) tag. With imgclass it is a plain image with that class, without figure and EXIF. */
    public static function image(KirbyTag $tag): string
    {
        $file = $tag->file($tag->value);
        if ($file === null) {
            return '';
        }
        if ($class = $tag->attr('imgclass')) {
            return '<img src="' . esc($file->url()) . '" alt="' . $file->alt()->esc() . '" class="' . esc($class) . '">';
        }
        return snippet('figure', ['file' => $file, 'page' => $tag->parent()], true);
    }

    /** The (map:) tag: the points as numbers in data-points, the route inline as JSON, both for map.js. */
    public static function map(KirbyTag $tag): string
    {
        $page = $tag->parent();
        if (!$page instanceof Page) {
            return '';
        }
        $points = [];
        foreach ($page->mappoints()->toStructure() as $point) {
            $points[] = ['label' => $point->label()->value(), 'lat' => (float) $point->lat()->value(), 'lon' => (float) $point->lon()->value()];
        }
        $route = $page->route()->toFile()?->read() ?? '';
        return '<div class="map"' . ($page->mapzoom()->toBool() ? ' data-zoomable="true"' : '')
            . ' data-points="' . htmlspecialchars(json_encode($points, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES) . '">'
            . ($route === '' ? '' : '<script type="application/json">' . $route . '</script>')
            . "\n</div>";
    }
}
