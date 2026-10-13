<?php

namespace Website\Changelog;

use Kirby\Cms\File;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use Kirby\Data\Yaml;
use Kirby\Toolkit\Str;
use Kirby\Uuid\Uuid;
use Throwable;

/**
 * Compares the old and the new version of a page, file, site or user for
 * the change log (Log). The result lists each changed field with its Panel
 * label, the old and the new value.
 */
final class Diff
{
    /** Fields that are never logged: `uuid` is an internal id, `password` is secret. */
    public const SKIP = ['uuid', 'password'];

    /** Longer values are cut after this many characters. */
    public const MAX = 200;

    /**
     * Changed content fields with label, old and new value (shortened).
     *
     * @return list<array{field: string, label: string, old: string, new: string}>
     */
    public static function changes(ModelWithContent $old, ModelWithContent $new): array
    {
        // A change of status, sort number or slug renames the page folder.
        // The old page object then finds no content file, and every field
        // would look new. Such a change logs only slug, status and number.
        $moved = $old instanceof Page && $new instanceof Page && ($old->status() !== $new->status() || $old->num() !== $new->num() || $old->slug() !== $new->slug());
        $a = $moved ? [] : $old->content()->toArray();
        $b = $moved ? [] : $new->content()->toArray();
        $changes = [];
        foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $key) {
            if (in_array($key, self::SKIP, true)) {
                continue;
            }
            $from = (string) ($a[$key] ?? '');
            $to = (string) ($b[$key] ?? '');
            if ($from === $to) {
                continue;
            }
            // For a list (structure, files, pages), the entry shows only the
            // removed and the added items.
            $fromList = self::items($from);
            $toList = self::items($to);
            if ($fromList !== null && $toList !== null) {
                $removed = array_values(array_diff($fromList, $toList));
                $added = array_values(array_diff($toList, $fromList));
                if ($removed === [] && $added === []) {
                    continue;
                }
                [$from, $to] = [implode(', ', $removed), implode(', ', $added)];
            }
            $changes[] = [
                'field' => $key,
                'label' => self::label($new, $key),
                'old' => Str::short($from, self::MAX),
                'new' => Str::short($to, self::MAX),
            ];
        }
        // Slug, status, sort number and file name are not content fields,
        // so the loop above does not see them.
        if ($old instanceof Page && $new instanceof Page) {
            if ($old->slug() !== $new->slug()) {
                $changes[] = ['field' => 'slug', 'label' => 'URL-Anhang', 'old' => $old->slug(), 'new' => $new->slug()];
            }
            // Kirby publishes a draft in two steps: draft to unlisted, then
            // changeNum() to listed. The post blueprints name only draft and
            // listed, so status() returns "Veröffentlicht" for unlisted and
            // for listed. Two states with the same name are no change.
            [$from, $to] = [self::status($old, $old->status()), self::status($new, $new->status())];
            if ($from !== $to) {
                $changes[] = ['field' => 'status', 'label' => 'Status', 'old' => $from, 'new' => $to];
            }
            // A status change sets or clears the sort number. That number
            // change is part of the status change and is not logged twice.
            if ($old->num() !== $new->num() && $old->status() === $new->status()) {
                $changes[] = ['field' => 'num', 'label' => 'Reihenfolge', 'old' => (string) $old->num(), 'new' => (string) $new->num()];
            }
        }
        if ($old instanceof File && $new instanceof File && $old->filename() !== $new->filename()) {
            $changes[] = ['field' => 'filename', 'label' => 'Dateiname', 'old' => $old->filename(), 'new' => $new->filename()];
        }
        return $changes;
    }

    /**
     * The name of a status in the page's blueprint, for example "Entwurf"
     * (draft) or "Veröffentlicht" (published). Without a name there, draft
     * is "Entwurf" and every other status "Veröffentlicht".
     */
    private static function status(Page $page, string $status): string
    {
        $label = $page->blueprint()->status()[$status]['label'] ?? null;
        return is_string($label) && $label !== '' ? $label : match ($status) {
            'draft' => 'Entwurf',
            default => 'Veröffentlicht',
        };
    }

    /**
     * Decodes a YAML list into readable items, or returns null for any other
     * value. A file or page reference (UUID or id) becomes the title of the
     * file or page. A structure row becomes its first value that is not
     * empty. Kirby can store a reference as an id and later as a UUID; both
     * become the same item, so the change log shows no change.
     *
     * @return list<string>|null
     */
    private static function items(string $value): ?array
    {
        if ($value !== '' && !str_starts_with($value, '-')) {
            return null;
        }
        try {
            $items = Yaml::decode($value);
        } catch (Throwable) {
            return null;
        }
        if (!array_is_list($items)) {
            return null;
        }
        return array_map(function ($item) {
            if (is_array($item)) {
                $first = array_filter($item, fn($v) => is_scalar($v) && (string) $v !== '');
                return (string) (reset($first) ?: '');
            }
            $item = (string) $item;
            $model = Uuid::is($item) ? Uuid::for($item)?->model() : (kirby()->file($item) ?? kirby()->page($item));
            return match (true) {
                $model instanceof File => Log::title($model),
                $model instanceof Page => $model->title()->value(),
                default => $item,
            };
        }, $items);
    }

    /**
     * The field label from the blueprint, or the field name with a capital
     * letter. Of a translated label, the German one wins: the Panel is German.
     */
    private static function label(ModelWithContent $model, string $key): string
    {
        $label = $model->blueprint()->field($key)['label'] ?? null;
        if (is_array($label)) {
            $label = $label['de'] ?? $label['en'] ?? reset($label);
        }
        return is_string($label) && $label !== '' ? $label : ucfirst($key);
    }
}
