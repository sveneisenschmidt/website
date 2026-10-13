<?php

use Kirby\Cms\File;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use Kirby\Data\Yaml;
use Website\Blog\Types;

/**
 * The content as the assistant reads it: posts, About and their files, each
 * field as the Panel shows it, and the route of a post. The read tools, the
 * propose tools and the click (apply.php) all read through these.
 */

/** A page by its id, drafts on every level included ("photos/aika"), or by its UUID. */
function blog_assistant_page(string $id): ?Page
{
    $id = trim($id, " /");
    if (str_starts_with($id, 'page://')) {
        return blog_guard_page($id);
    }
    $page = null;
    foreach ($id === '' ? [] : explode('/', $id) as $slug) {
        $page = ($page ?? site())->findPageOrDraft($slug);
        if ($page === null) {
            return null;
        }
    }
    return $page;
}

/** A post of the four sections, or About; null for any other page. */
function blog_assistant_find(string $ref): ?Page
{
    $page = blog_assistant_page($ref);
    $template = $page?->intendedTemplate()->name() ?? '';
    return $template === 'about' || isset(Types::ALL[$template]) ? $page : null;
}

/** A file of a post or of About by its id ("photos/aika/IMG_1.jpg"). */
function blog_assistant_file(string $id): ?File
{
    $page = blog_assistant_find(dirname(trim($id)));
    return $page?->file(basename(trim($id)));
}

/** A file of a page by its UUID ("file://…") or file name. */
function blog_assistant_file_ref(Page $page, string $ref): ?File
{
    return $page->files()->filter(fn(File $file) => $file->uuid()->toString() === $ref || $file->filename() === $ref)->first();
}

/** A YAML list as a field stores it (files, structure), or none. */
function blog_assistant_list(string $yaml): array
{
    return trim($yaml) === '' ? [] : array_values(Yaml::decode($yaml));
}

/** The fields the assistant reads and writes: name => [label, type]. The title first. */
function blog_assistant_fields(ModelWithContent $model): array
{
    $fields = $model instanceof Page ? ['title' => ['label' => 'Titel', 'type' => 'text']] : [];
    foreach ($model->blueprint()->fields() as $name => $field) {
        $label = $field['label'] ?? null;
        $fields[strtolower($name)] = ['label' => is_string($label) ? $label : ucfirst($name), 'type' => (string) ($field['type'] ?? 'text')];
    }
    return $fields;
}

/** A field's value as the Panel shows it: the unsaved changes if there are any. */
function blog_assistant_current(ModelWithContent $model, string $field): string
{
    $changes = $model->version('changes');
    $source = $changes->exists() ? $changes : $model->version('latest');
    return (string) ($source->read()[strtolower($field)] ?? '');
}

/** True when the field has unsaved changes in the Panel. */
function blog_assistant_unsaved(ModelWithContent $model, string $field): bool
{
    $changes = $model->version('changes');
    return $changes->exists() && blog_assistant_current($model, $field) !== (string) ($model->version('latest')->read()[strtolower($field)] ?? '');
}

/** The image file names of a post. */
function blog_assistant_filenames(Page $page): array
{
    return array_values($page->files()->template('image')->pluck('filename'));
}

/**
 * The route of a photo or trip as the Panel shows it: km, points, markers,
 * zoom. "hash" covers file, points and zoom as stored: a route card
 * compares it at the click.
 */
function blog_assistant_route_summary(Page $page): ?array
{
    if (!Types::hasMap($page->intendedTemplate()->name())) {
        return null;
    }
    $refs = blog_assistant_list(blog_assistant_current($page, 'route'));
    $file = isset($refs[0]) ? blog_assistant_file_ref($page, (string) $refs[0]) : null;
    $json = $file !== null ? (string) file_get_contents($file->root()) : '';
    $points = blog_assistant_current($page, 'mappoints');
    $zoom = blog_assistant_current($page, 'mapzoom');
    $line = blog_route_line($json);
    return [
        'file' => $file?->filename(),
        'km' => round(blog_route_length($line) / 1000, 1),
        'seconds' => null,
        'points' => count($line),
        'markers' => array_map(fn($p) => ['label' => (string) ($p['label'] ?? ''), 'lat' => (float) ($p['lat'] ?? 0), 'lon' => (float) ($p['lon'] ?? 0)], blog_assistant_list($points)),
        'zoom' => $zoom === 'true',
        'hash' => sha1($json . "\0" . $points . "\0" . $zoom),
    ];
}

/** The page that uses a slug in one of the four sections or at the top, drafts included, or null. */
function blog_assistant_slug_taken(string $slug): ?string
{
    foreach (Types::ALL as $info) {
        $page = site()->find($info['section'])?->childrenAndDrafts()->findBy('slug', $slug);
        if ($page !== null) {
            return $page->id();
        }
    }
    return site()->childrenAndDrafts()->findBy('slug', $slug)?->id();
}

/** True for a draft and the files of a draft. */
function blog_assistant_is_draft(ModelWithContent $model): bool
{
    $page = $model instanceof File ? $model->page() : $model;
    return $page instanceof Page && $page->isDraft();
}
