<?php

namespace Website\Tests\Integration;

use Kirby\Cms\Page;
use Kirby\Data\Data;
use Kirby\Filesystem\Dir;
use Kirby\Form\Form;
use PHPUnit\Framework\TestCase;
use Website\Tests\Support\HugoFixture;
use Website\Tests\Support\TestSite;

final class BlueprintTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TestSite::create();
        Dir::remove($this->root . '/content');
        hugo_import(HugoFixture::create($this->root), $this->root . '/content', 'blog_read_exif');
    }

    protected function tearDown(): void
    {
        TestSite::remove($this->root);
    }

    public function testEveryImportedPageAndFileHasItsBlueprint(): void
    {
        $kirby = TestSite::app($this->root);
        $pages = [
            'photos/aika' => 'Foto',
            'trips/2026-summer-vacation-5' => 'Reise',
            'notes/gemma' => 'Notiz',
            'essays/ambiguity' => 'Essay',
            'photos' => 'Fotos',
            'trips' => 'Reisen',
            'notes' => 'Notizen',
            'essays' => 'Essays',
            'about' => 'Über mich',
            'archive' => 'Archiv',
            'archive/essays' => 'Archiv',
            'search' => 'Suche',
        ];
        foreach ($pages as $id => $title) {
            $this->assertSame($title, $kirby->page($id)->blueprint()->title(), $id);
        }
        $this->assertSame('Website', $kirby->site()->blueprint()->title());

        $trip = $kirby->page('trips/2026-summer-vacation-5');
        foreach (['intro', 'text', 'date', 'cover', 'pick', 'trip', 'route', 'mappoints', 'mapzoom'] as $field) {
            $this->assertNotNull($trip->blueprint()->field($field), "trip: $field");
        }
        $this->assertSame('Bild', $trip->file('A6404180.jpeg')->blueprint()->title());
        $this->assertSame('Route', $trip->file('route.json')->blueprint()->title());
        foreach (['alt', 'caption', 'photographer', 'camera', 'lens', 'focal', 'aperture', 'shutter', 'iso'] as $field) {
            $this->assertNotNull($trip->file('A6404180.jpeg')->blueprint()->field($field), "image: $field");
        }
    }

    public function testAnUnchangedPanelSaveKeepsTheImportedValues(): void
    {
        $kirby = TestSite::app($this->root);
        $kirby->impersonate('kirby');

        // A post late in the evening: if the date field rounds, an unchanged
        // save moves it to the next day (and its folder number).
        $photo = $kirby->page('photos')->createChild([
            'slug' => 'late-night',
            'template' => 'photo',
            'content' => ['title' => 'Late night', 'date' => '2026-02-24 23:59:40'],
        ])->changeStatus('listed');
        $this->assertSame('2026-02-24 23:59:40', $this->panelSave($photo)['date']);

        $trip = $kirby->page('trips/2026-summer-vacation-5');
        $stored = $this->panelSave($trip);
        foreach (['date', 'intro', 'text', 'cover', 'route', 'trip', 'mappoints', 'mapzoom'] as $field) {
            // Files and structures come back decoded, the content file holds YAML.
            $imported = $trip->content()->get($field)->value();
            $expected = is_array($stored[$field]) ? Data::decode($imported, 'yaml') : $imported;
            $this->assertSame($expected, $stored[$field], "trip: $field");
        }
    }

    /**
     * What the Panel stores when the form is saved without a change: the form
     * values go back through a fresh form of the same page.
     */
    private function panelSave(Page $page): array
    {
        $values = Form::for($page)->toFormValues();
        return Form::for($page)->submit($values, force: true)->toStoredValues();
    }
}
