<?php

namespace Website\Blog;

use Kirby\Data\Yaml;
use Kirby\Form\Form;

/**
 * Settings of a module that are fields of the site. The plugin folder of
 * the module holds the blueprint of these fields (settings.yml), and the
 * module edits the fields on a Panel page of its own menu item, such as
 * Besucher › Einstellungen. The values stay in content/site.txt. The site
 * blueprint does not name these fields, and Kirby keeps them when the Panel
 * saves the site. Kirby does not check the rules of settings.yml on a save,
 * so a rule such as `required` has no effect there.
 */
final class Settings
{
    /**
     * The props of a settings page for the fields in the YAML file $file:
     * the props of each field and its value from the site.
     *
     * @return array{fields: array<string, mixed>, value: array<string, mixed>}
     */
    public static function props(string $file): array
    {
        $site = site();
        $form = new Form(fields: Yaml::read($file), model: $site);
        // Without passthrough the page gets only its own fields, and a save
        // sends only these back.
        $form->fill(input: $site->content()->toArray(), passthrough: false);
        return ['fields' => $form->toProps(), 'value' => $form->toFormValues()];
    }

    /**
     * The label of the settings field $key from the settings.yml of any
     * plugin, or null. Verlauf (Website\Changelog\Diff) shows it, because
     * the site blueprint does not name these fields.
     */
    public static function label(string $key): ?string
    {
        foreach (glob(kirby()->root('plugins') . '/*/settings.yml') ?: [] as $file) {
            foreach (Yaml::read($file) as $name => $field) {
                if (strtolower((string) $name) === strtolower($key) && is_string($field['label'] ?? null)) {
                    return $field['label'];
                }
            }
        }
        return null;
    }
}
