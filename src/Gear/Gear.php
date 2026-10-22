<?php

namespace Website\Gear;

use RuntimeException;
use Throwable;
use Website\Blog\JsonFile;

/**
 * The names of cameras and lenses on the site. The EXIF data of a photo
 * names its camera and lens as the maker writes them, such as
 * "SONY ILCE-6400A" (Website\Blog\Exif). The Panel view "Ausrüstung"
 * (plugins/gear) gives such a value a name for the lines under the photo
 * and marks gear as retired.
 *
 * Retired only moves gear to its own list in the view. The site does not
 * show it. The file content/.gear.json keeps both, keyed by the value from
 * the photo. The photo keeps its value.
 *
 * @phpstan-type Row array{raw: string, kind: 'camera'|'lens', name: string, retired: bool}
 * @phpstan-type Entry array{name: string, retired: bool}
 * @phpstan-type Values array{camera: list<string>, lens: list<string>}
 */
final class Gear
{
    public static function file(): string
    {
        return kirby()->root('content') . '/.gear.json';
    }

    /**
     * The entries of the file: value from the photo => name and retired. A
     * missing file gives none. A broken file gives none and a line in the PHP
     * error log, so a post shows the values from the photo.
     *
     * @return array<string, Entry>
     */
    public static function entries(): array
    {
        $file = self::file();
        if (!is_file($file)) {
            return [];
        }
        try {
            $data = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new RuntimeException(basename($file) . ' holds no object');
            }
        } catch (Throwable $e) {
            error_log('blog gear: ' . $e->getMessage());
            return [];
        }
        $entries = [];
        foreach ($data as $raw => $entry) {
            if (is_array($entry)) {
                $entries[(string) $raw] = [
                    'name' => is_string($entry['name'] ?? null) ? trim($entry['name']) : '',
                    'retired' => ($entry['retired'] ?? false) === true,
                ];
            }
        }
        return $entries;
    }

    /** The name of $raw on the site: its name from the file, or $raw itself without one. */
    public static function name(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $name = self::entries()[$raw]['name'] ?? '';
        return $name !== '' ? $name : $raw;
    }

    /**
     * The rows of the Panel view: every camera, then every lens, each with
     * its name ('' without one) and whether it is retired.
     *
     * @return list<Row>
     */
    public static function rows(): array
    {
        return self::build(self::values(), self::entries());
    }

    /**
     * Saves what the Panel view sends: value from the photo => name and
     * retired. The file keeps an entry only for a value of a photo, and only
     * with a name or retired. Kirby empties the page cache only on a change
     * of content, and this file is no content, so save() empties it.
     *
     * @param array<mixed> $input
     * @return list<Row> The rows after the save.
     */
    public static function save(array $input): array
    {
        $values = self::values();
        $known = array_flip([...$values['camera'], ...$values['lens']]);
        $entries = [];
        foreach ($input as $raw => $entry) {
            $raw = (string) $raw;
            if (!isset($known[$raw]) || !is_array($entry)) {
                continue;
            }
            $name = is_string($entry['name'] ?? null) ? trim($entry['name']) : '';
            $retired = ($entry['retired'] ?? false) === true;
            if ($name !== '' || $retired) {
                $entries[$raw] = ['name' => $name, 'retired' => $retired];
            }
        }
        ksort($entries, SORT_NATURAL | SORT_FLAG_CASE);
        JsonFile::write(self::file(), $entries);
        kirby()->cache('pages')->flush();
        return self::build($values, $entries);
    }

    /**
     * @param Values $values
     * @param array<string, Entry> $entries
     * @return list<Row>
     */
    private static function build(array $values, array $entries): array
    {
        $rows = [];
        foreach ($values as $kind => $list) {
            foreach ($list as $raw) {
                $rows[] = ['raw' => $raw, 'kind' => $kind, 'name' => $entries[$raw]['name'] ?? '', 'retired' => $entries[$raw]['retired'] ?? false];
            }
        }
        return $rows;
    }

    /**
     * The values of the fields camera and lens of all files on all pages,
     * drafts included. Each value is in its list once, sorted as people read
     * it ("RF24-105mm" before "RF200-800mm"). On 2026-10-22 the site had 700
     * photos with camera data, and the walk took about 0.1 s.
     *
     * @return Values
     */
    private static function values(): array
    {
        $found = ['camera' => [], 'lens' => []];
        foreach (kirby()->site()->index(true) as $page) {
            foreach ($page->files() as $file) {
                foreach (array_keys($found) as $kind) {
                    $value = trim((string) $file->content()->get($kind)->value());
                    if ($value !== '') {
                        $found[$kind][$value] = true;
                    }
                }
            }
        }
        $values = [];
        foreach ($found as $kind => $set) {
            // A key such as "100" is an int in a PHP array.
            $list = array_map('strval', array_keys($set));
            sort($list, SORT_NATURAL | SORT_FLAG_CASE);
            $values[$kind] = $list;
        }
        return $values;
    }
}
