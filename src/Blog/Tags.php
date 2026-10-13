<?php

namespace Website\Blog;

use Kirby\Cms\Page;
use Kirby\Text\KirbyTag;

/**
 * The KirbyText tags that plugins/blog registers. KirbyText is Kirby's
 * Markdown with tags in parentheses, such as (image: photo.jpg). (image:)
 * renders a figure (snippets/figure.php), (map:) the map of a photo or trip
 * post. The HTML of each tag contains no blank line: Markdown ends an HTML
 * block at a blank line and would treat the rest as Markdown text.
 */
final class Tags
{
    /** With the attribute imgclass, the tag renders a plain <img> with that class, without a figure and without EXIF lines. */
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

    /**
     * The container that assets/js/map.js turns into a map. The points of the
     * field `mappoints` go into data-points as JSON. The route file (GeoJSON,
     * a JSON format for geographic data) goes inline into a JSON script element.
     */
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
