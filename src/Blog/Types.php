<?php

namespace Website\Blog;

/**
 * The four post types: template => the section that holds them, the name
 * of one post (Panel, assistant, Verlauf), the menu entry in the Panel
 * (label and icon) and whether a post has a map.
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
     * The templates of the four types.
     *
     * @return list<string>
     */
    public static function templates(): array
    {
        return array_keys(self::ALL);
    }

    /**
     * The sections that hold posts.
     *
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
