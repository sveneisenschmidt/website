<?php

namespace Website\Blog;

/**
 * The four post types, keyed by template name. `section` is the parent page
 * that holds the posts of the type. `label` is the German name of one post
 * in the Panel, the assistant and the change log. `menu` and `icon` make
 * the entry in the Panel menu. `map` says whether a post can have a map.
 */
final class Types
{
    /** @var array<string, array{section: string, label: string, menu: string, icon: string, map: bool}> */
    public const ALL = [
        'photo' => ['section' => 'photos', 'label' => 'Foto', 'menu' => 'Fotos', 'icon' => 'image', 'map' => true],
        'trip' => ['section' => 'trips', 'label' => 'Reise', 'menu' => 'Reisen', 'icon' => 'map', 'map' => true],
        'note' => ['section' => 'notes', 'label' => 'Notiz', 'menu' => 'Notizen', 'icon' => 'text', 'map' => false],
        'essay' => ['section' => 'essays', 'label' => 'Essay', 'menu' => 'Essays', 'icon' => 'book', 'map' => false],
    ];

    /**
     * @return list<string>
     */
    public static function templates(): array
    {
        return array_keys(self::ALL);
    }

    /**
     * @return list<string>
     */
    public static function sections(): array
    {
        return array_column(self::ALL, 'section');
    }

    /** True for a post type that can have a map (photo, trip). */
    public static function hasMap(string $template): bool
    {
        return self::ALL[$template]['map'] ?? false;
    }
}
