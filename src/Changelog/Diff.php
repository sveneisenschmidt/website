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
 * What a change changed, for an entry of the change log (Log): each
 * field with its label, the old and the new value.
 */
final class Diff
{
    /** Fields that are not content: never logged. */
    public const SKIP = ['uuid', 'password'];

    /** Fields whose values never go into the log: a change shows as "geändert". */
    public const SECRET = ['assistantprompt'];

    /** Longer values are cut after this many characters. */
    public const MAX = 200;

    /**
     * Changed content fields with label, old and new value (shortened).
     *
     * @return list<array{field: string, label: string, old: string, new: string}>
     */
    public static function changes(ModelWithContent $old, ModelWithContent $new): array
    {
        // A status, sort or slug change moves the page folder: the old object then
        // reads no content, and every field would look new.
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
            // The assistant's prompt lives only in content/site.txt.
            if (in_array(strtolower($key), self::SECRET, true)) {
                $changes[] = ['field' => $key, 'label' => self::label($new, $key), 'old' => '', 'new' => 'geändert'];
                continue;
            }
            // Lists (structure, files, pages): the rows that went and came.
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
        // Slug and status are no content fields.
        if ($old instanceof Page && $new instanceof Page) {
            if ($old->slug() !== $new->slug()) {
                $changes[] = ['field' => 'slug', 'label' => 'URL-Anhang', 'old' => $old->slug(), 'new' => $new->slug()];
            }
            // Publishing goes draft, unlisted, listed: Kirby logs the last step as a
            // sort change. Two states with the same Panel name are no change.
            [$from, $to] = [self::status($old, $old->status()), self::status($new, $new->status())];
            if ($from !== $to) {
                $changes[] = ['field' => 'status', 'label' => 'Status', 'old' => $from, 'new' => $to];
            }
            // A status change sets or clears the number itself: not a second change.
            if ($old->num() !== $new->num() && $old->status() === $new->status()) {
                $changes[] = ['field' => 'num', 'label' => 'Reihenfolge', 'old' => (string) $old->num(), 'new' => (string) $new->num()];
            }
        }
        if ($old instanceof File && $new instanceof File && $old->filename() !== $new->filename()) {
            $changes[] = ['field' => 'filename', 'label' => 'Dateiname', 'old' => $old->filename(), 'new' => $new->filename()];
        }
        return $changes;
    }

    /** A page status as the Panel names it for this page: "Entwurf", "Veröffentlicht". */
    private static function status(Page $page, string $status): string
    {
        $label = $page->blueprint()->status()[$status]['label'] ?? null;
        return is_string($label) && $label !== '' ? $label : match ($status) {
            'draft' => 'Entwurf',
            default => 'Veröffentlicht',
        };
    }

    /**
     * A YAML list as readable items, or null for any other value.
     * A file or page reference (UUID or id) becomes its name, a structure row
     * its first value: a reference written as id and later as UUID is the same item.
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

    /** The field label from the blueprint, or the field name. */
    private static function label(ModelWithContent $model, string $key): string
    {
        $label = $model->blueprint()->field($key)['label'] ?? null;
        if (is_array($label)) {
            $label = $label['de'] ?? $label['en'] ?? reset($label);
        }
        return is_string($label) && $label !== '' ? $label : ucfirst($key);
    }
}
