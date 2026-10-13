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
            return rtrim(snippet('image-inline', ['file' => $file, 'class' => (string) $class], true), "\n");
        }
        return snippet('figure', ['file' => $file, 'page' => $tag->parent()], true);
    }

    /**
     * The map of a post (snippets/map.php): the points of the field
     * `mappoints` and the route file (GeoJSON, a JSON format for geographic
     * data).
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
        // The route file comes from the Panel and can hold any JSON. Encoded
        // again with JSON_HEX_TAG, it has no "<", so a "</script>" in a
        // property cannot end the script element of the snippet. A file that
        // is no JSON gives no route; map.js could not read it either.
        $data = json_decode((string) ($page->route()->toFile()?->read() ?? ''));
        $route = $data === null ? '' : (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        return rtrim(snippet('map', ['zoomable' => $page->mapzoom()->toBool(), 'points' => $points, 'route' => $route], true), "\n");
    }
}
