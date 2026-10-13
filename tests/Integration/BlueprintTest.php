<?php

namespace Website\Tests\Integration;

use Kirby\Cms\Page;
use Kirby\Data\Data;
use Kirby\Form\Form;

final class BlueprintTest extends IntegrationTestCase
{
    private Page $trip;

    /**
     * One page of every template, and a trip with every field, an image and
     * a route. The trip's fields are written as the content files on the
     * server hold them, not through the Panel.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $aika = $this->post('photos', 'photo', 'aika', 'Aika');
        $this->post('notes', 'note', 'gemma', 'Gemma');
        $this->post('essays', 'essay', 'ambiguity', 'Ambiguity');

        $this->trip = $this->post('trips', 'trip', '2026-summer-vacation-5', 'Summer Vacation, Day 5: Brighton');
        $image = $this->upload($this->jpeg(8, 8), 'A6404180.jpeg', $this->trip);
        file_put_contents($this->root . '/route.json', '{"type":"LineString","coordinates":[[0.3924,50.8297],[-0.097,50.8224]]}');

        $this->kirby->impersonate('kirby', function () use ($aika, $image) {
            $route = $this->trip->createFile(['source' => $this->root . '/route.json', 'filename' => 'route.json', 'template' => 'route']);
            // @phpstan-ignore argument.type (Kirby's doc type says string values, update() takes lists and structures too)
            $this->trip->version()->update([
                'intro' => 'We packed up and met (link: ' . $aika->uuid() . ' text: Aika) again.',
                'text' => "(image: A6404180.jpeg)\n\n(map:)",
                'cover' => [(string) $image->uuid()],
                'trip' => 'summer-2026',
                'route' => [(string) $route->uuid()],
                'mappoints' => [
                    ['label' => "Norman's Bay, East Sussex", 'lat' => '50.8297', 'lon' => '0.3924'],
                    ['label' => 'Brighton', 'lat' => '50.8224', 'lon' => '-0.0970'],
                ],
                'mapzoom' => 'true',
            ]);

            $site = $this->kirby->site();
            $site->createChild(['slug' => 'about', 'template' => 'about', 'content' => ['title' => 'About', 'label' => 'Personal blog']])->changeStatus('unlisted');
            $site->createChild(['slug' => 'archive', 'template' => 'archive', 'content' => ['title' => 'Archive', 'stream' => 'all']])
                ->changeStatus('unlisted')
                ->createChild(['slug' => 'essays', 'template' => 'archive', 'content' => ['title' => 'Archive · Essays', 'stream' => 'essays']])
                ->changeStatus('unlisted');
            $site->createChild(['slug' => 'search', 'template' => 'search', 'content' => ['title' => 'Search']])->changeStatus('unlisted');
        });
    }

    public function testEveryPageAndFileHasItsBlueprint(): void
    {
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
            $this->assertSame($title, $this->kirby->page($id)->blueprint()->title(), $id);
        }
        $this->assertSame('Website', $this->kirby->site()->blueprint()->title());

        $trip = $this->kirby->page('trips/2026-summer-vacation-5');
        foreach (['intro', 'text', 'date', 'cover', 'pick', 'trip', 'route', 'mappoints', 'mapzoom'] as $field) {
            $this->assertNotNull($trip->blueprint()->field($field), "trip: $field");
        }
        $this->assertSame('Bild', $trip->file('A6404180.jpeg')->blueprint()->title());
        $this->assertSame('Route', $trip->file('route.json')->blueprint()->title());
        foreach (['alt', 'caption', 'photographer', 'camera', 'lens', 'focal', 'aperture', 'shutter', 'iso'] as $field) {
            $this->assertNotNull($trip->file('A6404180.jpeg')->blueprint()->field($field), "image: $field");
        }
    }

    public function testAnUnchangedPanelSaveKeepsTheStoredValues(): void
    {
        $this->kirby->impersonate('kirby');

        // A post late in the evening: if the date field rounds, an unchanged
        // save moves it to the next day (and its folder number).
        $photo = $this->kirby->page('photos')->createChild([
            'slug' => 'late-night',
            'template' => 'photo',
            'content' => ['title' => 'Late night', 'date' => '2026-02-24 23:59:40'],
        ])->changeStatus('listed');
        $this->assertSame('2026-02-24 23:59:40', $this->panelSave($photo)['date']);

        $trip = $this->kirby->page('trips/2026-summer-vacation-5');
        $stored = $this->panelSave($trip);
        foreach (['date', 'intro', 'text', 'cover', 'route', 'trip', 'mappoints', 'mapzoom'] as $field) {
            // Files and structures come back decoded, the content file holds YAML.
            $value = $trip->content()->get($field)->value();
            $expected = is_array($stored[$field]) ? Data::decode($value, 'yaml') : $value;
            $this->assertSame($expected, $stored[$field], "trip: $field");
        }
    }

    /**
     * What the Panel stores when the form is saved without a change: the form
     * values go back through a fresh form of the same page.
     *
     * @return array<mixed>
     */
    private function panelSave(Page $page): array
    {
        $values = Form::for($page)->toFormValues();
        return Form::for($page)->submit($values, force: true)->toStoredValues();
    }
}
