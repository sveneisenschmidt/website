<?php

use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Text\KirbyTag;

/**
 * The KirbyText tags of a post: (image:) draws a figure (snippets/figure.php),
 * (map:) the map of a trip. Both stay one HTML block without blank lines,
 * so Markdown leaves them as they are.
 */

/** The EXIF rows of an image: label => value, only the values the file has. */
function blog_exif_rows(File $file): array
{
    $value = fn(string $key) => trim((string) $file->content()->get($key)->value());
    $exposure = '';
    if (($shutter = $value('shutter')) !== '') {
        $exposure = str_starts_with($shutter, '1/') ? $shutter . 's' : sprintf('%.1fs', (float) $shutter);
    }
    if (($iso = $value('iso')) !== '') {
        $exposure = $exposure === '' ? "ISO $iso" : "$exposure · ISO $iso";
    }
    $aperture = $value('aperture');
    if ($aperture !== '') {
        $f = (float) $aperture;
        $aperture = floor($f) === $f ? sprintf('ƒ/%.0f', $f) : sprintf('ƒ/%.1f', $f);
    }
    return array_filter([
        'Camera' => $value('camera'),
        'Lens' => $value('lens'),
        'Focal length' => $value('focal') === '' ? '' : sprintf('%.0fmm', (float) $value('focal')),
        'Aperture' => $aperture,
        'Exposure' => $exposure,
    ], fn($v) => $v !== '');
}

/** The (image:) tag. With imgclass it is a plain image with that class, without figure and EXIF. */
function blog_image_tag(KirbyTag $tag): string
{
    $file = $tag->file($tag->value);
    if ($file === null) {
        return '';
    }
    if ($tag->imgclass) {
        return '<img src="' . esc($file->url()) . '" alt="' . $file->alt()->esc() . '" class="' . esc($tag->imgclass) . '">';
    }
    return snippet('figure', ['file' => $file, 'page' => $tag->parent()], true);
}

/** The (map:) tag: the points as numbers in data-points, the route inline as JSON, both for map.js. */
function blog_map_tag(KirbyTag $tag): string
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
