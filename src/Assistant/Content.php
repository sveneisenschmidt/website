<?php

namespace Website\Assistant;

use Kirby\Cms\File;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use Kirby\Data\Yaml;
use Website\Blog\Types;

/**
 * Finds and reads the pages the assistant may work on: the posts of the four
 * sections and About, with their files. A field reads as the Panel shows it,
 * unsaved changes included. The read tools (`Tools`), the checks of the
 * proposals (`Proposals`) and `Apply` all read content through this class,
 * so all three see the same values.
 */
final class Content
{
    /**
     * A page by its id ("photos/aika") or by its UUID ("page://…"). Kirby's
     * `find()` skips drafts, so the loop looks up each slug with
     * `findPageOrDraft()`.
     */
    public static function page(string $id): ?Page
    {
        $id = trim($id, " /");
        if (str_starts_with($id, 'page://')) {
            return Guard::page($id);
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

    /** A post or About by id or UUID. Any other page gives null, so the assistant cannot touch it. */
    public static function find(string $ref): ?Page
    {
        $page = self::page($ref);
        $template = $page?->intendedTemplate()->name() ?? '';
        return $template === 'about' || isset(Types::ALL[$template]) ? $page : null;
    }

    /** A file of a post or of About by its id ("photos/aika/IMG_1.jpg"). */
    public static function file(string $id): ?File
    {
        $page = self::find(dirname(trim($id)));
        return $page?->file(basename(trim($id)));
    }

    /** A file of a page by its UUID ("file://…") or file name. */
    public static function fileRef(Page $page, string $ref): ?File
    {
        return $page->files()->filter(fn(File $file) => $file->uuid()->toString() === $ref || $file->filename() === $ref)->first();
    }

    /**
     * Decodes a YAML list as a `files` or `structure` field stores it. An
     * empty field gives an empty list.
     *
     * @return list<mixed>
     */
    public static function list(string $yaml): array
    {
        return trim($yaml) === '' ? [] : array_values(Yaml::decode($yaml));
    }

    /**
     * The fields of a model's blueprint, by lowercase name, as Kirby stores
     * them: name => [label, type]. A page gets "title" first, because Kirby's
     * blueprints do not list the title as a field.
     *
     * @return array<string, array{label: string, type: string}>
     */
    public static function fields(ModelWithContent $model): array
    {
        $fields = $model instanceof Page ? ['title' => ['label' => 'Titel', 'type' => 'text']] : [];
        foreach ($model->blueprint()->fields() as $name => $field) {
            $label = $field['label'] ?? null;
            $fields[strtolower($name)] = ['label' => is_string($label) ? $label : ucfirst($name), 'type' => (string) ($field['type'] ?? 'text')];
        }
        return $fields;
    }

    /**
     * A field's value as the Panel shows it. Kirby 5 keeps edits that are not
     * saved yet in the version `changes` and the saved content in `latest`;
     * this reads `changes` when it exists.
     */
    public static function current(ModelWithContent $model, string $field): string
    {
        $changes = $model->version('changes');
        $source = $changes->exists() ? $changes : $model->version('latest');
        return (string) ($source->read()[strtolower($field)] ?? '');
    }

    /** True when the field has unsaved changes in the Panel. */
    public static function unsaved(ModelWithContent $model, string $field): bool
    {
        $changes = $model->version('changes');
        return $changes->exists() && self::current($model, $field) !== (string) ($model->version('latest')->read()[strtolower($field)] ?? '');
    }

    /**
     * @return list<string>
     */
    public static function filenames(Page $page): array
    {
        return array_values($page->files()->template('image')->pluck('filename'));
    }

    /**
     * The route of a photo or trip post as the Panel shows it: km, points,
     * markers, zoom. Other post types have no map and give null. "hash"
     * covers the route file and the fields `mappoints` and `mapzoom`. A
     * proposed route stores it, and `Apply` refuses the proposal when the
     * hash differs at the click.
     *
     * @return ?array<string, mixed>
     */
    public static function routeSummary(Page $page): ?array
    {
        if (!Types::hasMap($page->intendedTemplate()->name())) {
            return null;
        }
        $refs = self::list(self::current($page, 'route'));
        $file = isset($refs[0]) ? self::fileRef($page, (string) $refs[0]) : null;
        $json = $file !== null ? (string) file_get_contents($file->root()) : '';
        $points = self::current($page, 'mappoints');
        $zoom = self::current($page, 'mapzoom');
        $line = Route::line($json);
        return [
            'file' => $file?->filename(),
            'km' => round(Route::length($line) / 1000, 1),
            'seconds' => null,
            'points' => count($line),
            'markers' => array_map(fn($p) => ['label' => (string) ($p['label'] ?? ''), 'lat' => (float) ($p['lat'] ?? 0), 'lon' => (float) ($p['lon'] ?? 0)], self::list($points)),
            'zoom' => $zoom === 'true',
            'hash' => sha1($json . "\0" . $points . "\0" . $zoom),
        ];
    }

    /**
     * The id of the page that already uses a slug, drafts included, or null.
     * A post URL (/YYYY-MM-DD-slug/) does not name the section, and `Router`
     * takes the first section that has the slug. So a new slug must be free
     * in all four sections; top-level pages count too.
     */
    public static function slugTaken(string $slug): ?string
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
    public static function isDraft(ModelWithContent $model): bool
    {
        $page = $model instanceof File ? $model->page() : $model;
        return $page instanceof Page && $page->isDraft();
    }
}
