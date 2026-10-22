<?php

namespace Website\Tests\Integration;

use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Exception\AuthException;
use Website\Blog\Text;
use Website\Gear\Gear;
use Website\Tests\Support\TestSite;

/**
 * The gear module (Website\Gear\Gear, plugins/gear). The Panel view
 * "Ausrüstung" lists every camera and lens of the photos. A name there
 * replaces the value from the photo in the lines under the photo. Retired
 * gear moves to its own list in the view and changes nothing on the site.
 */
final class GearTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        // Kirby writes the session at PHP shutdown, after parent::tearDown()
        // has deleted its folder. So the test destroys the session first.
        $this->kirby->session()->destroy();
        parent::tearDown();
    }

    public function testWithoutAFileEveryValueIsItsOwnName(): void
    {
        $this->assertSame([], Gear::entries());
        $this->assertSame('SONY ILCE-6400A', Gear::name('SONY ILCE-6400A'));
        $this->assertSame('', Gear::name(''));
    }

    public function testANameFromTheFileReplacesTheValue(): void
    {
        file_put_contents(Gear::file(), json_encode([
            'SONY ILCE-6400A' => ['name' => 'Sony α6400', 'retired' => false],
            'Canon EOS 2000D' => ['name' => '', 'retired' => true],
        ]));

        $this->assertSame('Sony α6400', Gear::name('SONY ILCE-6400A'));
        $this->assertSame('Canon EOS 2000D', Gear::name('Canon EOS 2000D'), 'retired without a name');
        $this->assertSame('Canon EOS R5', Gear::name('Canon EOS R5'), 'no entry');
    }

    /** A broken file breaks no post: the lines under the photo show the values from the photo. */
    public function testABrokenFileGivesTheValuesFromThePhoto(): void
    {
        foreach (['not json' => 'blog gear: Syntax error', '"a string"' => 'blog gear: .gear.json holds no object'] as $content => $message) {
            file_put_contents(Gear::file(), $content);

            [$name, $log] = $this->logged(fn() => Gear::name('SONY ILCE-6400A'));

            $this->assertSame('SONY ILCE-6400A', $name);
            $this->assertStringContainsString($message, $log);
        }
    }

    /**
     * The view lists every camera, then every lens, each value once and
     * sorted as people read it: "iPhone" before "RF", "RF24-105mm" before
     * "RF200-800mm". Drafts count. A photo without camera and lens adds
     * nothing.
     */
    public function testTheRowsAreEveryCameraAndLensOfThePhotos(): void
    {
        $this->photo('SONY ILCE-6400A', 'E PZ 16-50mm F3.5-5.6 OSS II', 'a.jpg');
        $this->photo('SONY ILCE-6400A', 'E 55-210mm F4.5-6.3 OSS', 'b.jpg');
        $draft = $this->post(slug: 'draft-photo', status: 'draft');
        $this->photo('Canon EOS R5', 'RF200-800mm F6.3-9 IS USM', 'c.jpg', $draft);
        $this->photo('Canon EOS R5', 'RF24-105mm F4 L IS USM', 'd.jpg', $draft);
        $this->photo('Apple iPhone Air', 'iPhone Air back camera 5.96mm f/1.6', 'e.jpg', $draft);
        $this->upload($this->jpeg(8, 8, 'plain.jpg'), 'plain.jpg');
        $this->fresh();

        $this->assertSame([
            ['camera', 'Apple iPhone Air'],
            ['camera', 'Canon EOS R5'],
            ['camera', 'SONY ILCE-6400A'],
            ['lens', 'E 55-210mm F4.5-6.3 OSS'],
            ['lens', 'E PZ 16-50mm F3.5-5.6 OSS II'],
            ['lens', 'iPhone Air back camera 5.96mm f/1.6'],
            ['lens', 'RF24-105mm F4 L IS USM'],
            ['lens', 'RF200-800mm F6.3-9 IS USM'],
        ], array_map(fn(array $row) => [$row['kind'], $row['raw']], Gear::rows()));
    }

    /** A row has the name and retired from the file, and '' and false without an entry. */
    public function testTheRowsHaveTheEntries(): void
    {
        $this->photo('SONY ILCE-6400A', 'E PZ 16-50mm F3.5-5.6 OSS II');
        $this->fresh();
        file_put_contents(Gear::file(), json_encode(['SONY ILCE-6400A' => ['name' => 'Sony α6400', 'retired' => true]]));

        $this->assertSame([
            ['raw' => 'SONY ILCE-6400A', 'kind' => 'camera', 'name' => 'Sony α6400', 'retired' => true],
            ['raw' => 'E PZ 16-50mm F3.5-5.6 OSS II', 'kind' => 'lens', 'name' => '', 'retired' => false],
        ], Gear::rows());
    }

    /** A value that is the camera of one photo and the lens of another is in both lists. */
    public function testAValueCanBeCameraAndLens(): void
    {
        $this->photo('Apple iPhone Air', 'Apple iPhone Air');
        $this->fresh();

        $this->assertSame([['camera', 'Apple iPhone Air'], ['lens', 'Apple iPhone Air']], array_map(fn(array $row) => [$row['kind'], $row['raw']], Gear::rows()));
    }

    /**
     * The file keeps an entry only for a value of a photo, and only with a
     * name or retired. Names lose their spaces at both ends. The file is
     * sorted by the same rule as the lists of the view: "iPhone" between
     * "Canon" and "SONY". A save replaces the file, so a cleared name is
     * gone.
     */
    public function testSaveKeepsTheNamesAndRetiredGearOfThePhotos(): void
    {
        $this->photo('SONY ILCE-6400A', 'E PZ 16-50mm F3.5-5.6 OSS II', 'a.jpg');
        $this->photo('Canon EOS 2000D', 'EF-S18-55mm f/3.5-5.6 IS II', 'b.jpg');
        $this->photo('Apple iPhone Air', 'iPhone Air back camera 5.96mm f/1.6', 'c.jpg');
        $this->fresh();
        file_put_contents(Gear::file(), json_encode(['E PZ 16-50mm F3.5-5.6 OSS II' => ['name' => 'Sony 16-50mm', 'retired' => false]]));

        $rows = Gear::save([
            'SONY ILCE-6400A' => ['name' => '  Sony α6400 ', 'retired' => false],
            'Canon EOS 2000D' => ['name' => '', 'retired' => true],
            'iPhone Air back camera 5.96mm f/1.6' => ['name' => 'iPhone Air', 'retired' => false],
            'E PZ 16-50mm F3.5-5.6 OSS II' => ['name' => ' ', 'retired' => false],
            'Canon EOS R5' => ['name' => 'Canon R5', 'retired' => false],
            'EF-S18-55mm f/3.5-5.6 IS II' => 'not an entry',
        ]);

        $this->assertSame([
            'Canon EOS 2000D' => ['name' => '', 'retired' => true],
            'iPhone Air back camera 5.96mm f/1.6' => ['name' => 'iPhone Air', 'retired' => false],
            'SONY ILCE-6400A' => ['name' => 'Sony α6400', 'retired' => false],
        ], json_decode((string) file_get_contents(Gear::file()), true));
        $this->assertSame(Gear::rows(), $rows);
    }

    /** The page cache keeps the lines under the photos, so a save empties it. */
    public function testSaveEmptiesThePageCache(): void
    {
        $this->kirby->cache('pages')->set('photos/test-photo.latest.html', ['html' => '<p>old</p>']);

        Gear::save([]);

        $this->assertFalse($this->kirby->cache('pages')->exists('photos/test-photo.latest.html'));
    }

    /**
     * The camera line shows the name of the camera. The lens has no name, so
     * its line shows the value from the photo. Retired changes nothing on the
     * site.
     */
    public function testTheLinesUnderThePhotoShowTheNames(): void
    {
        $this->photo('SONY ILCE-6400A', 'E PZ 16-50mm F3.5-5.6 OSS II');
        $this->fresh();
        Gear::save(['SONY ILCE-6400A' => ['name' => 'Sony α6400', 'retired' => true]]);

        $html = Text::html('(image: a.jpg)', $this->kirby->page('photos/test-photo') ?? $this->fail('no post'));

        $this->assertStringContainsString("<dt>Camera</dt>\n<dd>Sony α6400</dd>", $html);
        $this->assertStringContainsString("<dt>Lens</dt>\n<dd>E PZ 16-50mm F3.5-5.6 OSS II</dd>", $html);
    }

    /**
     * The Panel has its own view "Ausrüstung", with an entry in the menu
     * after the post types. The view gets the rows as its prop `rows`.
     */
    public function testThePanelViewGetsTheRows(): void
    {
        $this->photo('SONY ILCE-6400A', 'E PZ 16-50mm F3.5-5.6 OSS II');
        $this->fresh();

        $area = $this->kirby->extensions('areas')['ausruestung'][0]();
        $view = $area['views'][0]['action']();
        $menu = $this->kirby->option('panel.menu')($this->kirby);
        $keys = array_keys($menu);
        $values = array_values($menu);
        $at = array_search('ausruestung', $values, true);

        $this->assertSame(['label' => 'Ausrüstung', 'icon' => 'tag', 'menu' => true, 'link' => 'ausruestung'], array_intersect_key($area, array_flip(['label', 'icon', 'menu', 'link'])));
        $this->assertSame('k-gear-view', $view['component']);
        $this->assertSame(Gear::rows(), $view['props']['rows']);
        $this->assertIsInt($at);
        $this->assertSame('essays', $keys[$at - 1]);
        $this->assertSame('-', $values[$at + 1]);
    }

    /**
     * The view sends one entry per value from the photo, as a map from the
     * value to name and retired. The API route saves it and returns the rows.
     */
    public function testTheApiSavesTheEntries(): void
    {
        $this->photo('SONY ILCE-6400A', 'E PZ 16-50mm F3.5-5.6 OSS II');
        $this->options['api'] = ['allowImpersonation' => true];
        $this->fresh();
        $this->kirby->impersonate('kirby');

        $result = $this->kirby->api()->call('gear/save', 'POST', ['body' => ['entries' => [
            'SONY ILCE-6400A' => ['name' => 'Sony α6400', 'retired' => false],
            'E PZ 16-50mm F3.5-5.6 OSS II' => ['name' => '', 'retired' => true],
        ]]]);

        $this->assertSame(['rows' => Gear::rows()], $result);
        $this->assertSame('Sony α6400', Gear::name('SONY ILCE-6400A'));
        $this->assertTrue(Gear::entries()['E PZ 16-50mm F3.5-5.6 OSS II']['retired']);
    }

    public function testWithoutALoginTheApiRefuses(): void
    {
        $this->kirby->impersonate();

        try {
            $this->kirby->api()->call('gear/save', 'POST', ['body' => ['entries' => []]]);
            $this->fail('No error');
        } catch (AuthException) {
        }
        $this->assertFileDoesNotExist(Gear::file());
    }

    /** Uploads a photo to the post (or to $page) and gives it a camera and a lens. */
    private function photo(string $camera, string $lens, string $name = 'a.jpg', ?Page $page = null): File
    {
        $file = $this->upload($this->jpeg(8, 8, $name), $name, $page);
        return $this->kirby->impersonate('kirby', fn() => $file->update(['camera' => $camera, 'lens' => $lens]));
    }

    /** A new Kirby reads pages and files from disk, as the next request does. */
    private function fresh(): void
    {
        $this->kirby = TestSite::app($this->root, $this->options);
    }
}
