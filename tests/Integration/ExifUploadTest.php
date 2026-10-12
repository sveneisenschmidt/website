<?php

namespace Website\Tests\Integration;

use Kirby\Data\Txt;
use PHPUnit\Framework\TestCase;
use Website\Tests\Support\TestSite;

final class ExifUploadTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TestSite::create();
    }

    protected function tearDown(): void
    {
        TestSite::remove($this->root);
    }

    public function testUploadStoresExifAsFileFields(): void
    {
        $kirby = TestSite::app($this->root);
        $file = $kirby->impersonate('kirby', fn () => $kirby->site()
            ->createChild(['slug' => 'aika', 'template' => 'photo', 'content' => ['title' => 'Aika']])
            ->createFile([
                'source' => __DIR__ . '/../fixtures/exif.jpg',
                'filename' => 'aika.jpg',
            ]));

        $this->assertSame('TEST-CAM', $file->camera()->value());
        $this->assertSame('Test Lens 24mm', $file->lens()->value());
        $this->assertSame('24', $file->focal()->value());
        $this->assertSame('2.8', $file->aperture()->value());
        $this->assertSame('1/250', $file->shutter()->value());
        $this->assertSame('3200', $file->iso()->value());
    }

    public function testValueGivenOnCreateWins(): void
    {
        $kirby = TestSite::app($this->root);
        $file = $kirby->impersonate('kirby', fn () => $kirby->site()
            ->createChild(['slug' => 'aika', 'template' => 'photo', 'content' => ['title' => 'Aika']])
            ->createFile([
                'source' => __DIR__ . '/../fixtures/exif.jpg',
                'filename' => 'aika.jpg',
                'content' => ['camera' => 'My Camera'],
            ]));

        $this->assertSame('My Camera', $file->camera()->value());
        $this->assertSame('Test Lens 24mm', $file->lens()->value());
    }

    public function testReplaceTakesTheExifOfTheNewFile(): void
    {
        $kirby = TestSite::app($this->root);
        $plain = $this->root . '/plain.jpg';
        imagejpeg(imagecreatetruecolor(8, 8), $plain, 70);
        $file = $kirby->impersonate('kirby', fn () => $kirby->site()
            ->createChild(['slug' => 'aika', 'template' => 'photo', 'content' => ['title' => 'Aika']])
            ->createFile(['source' => __DIR__ . '/../fixtures/exif.jpg', 'filename' => 'aika.jpg']));

        $kirby->impersonate('kirby', fn () => $file->replace($plain));

        // Read from disk: the new file has no EXIF, so all six fields are gone.
        $meta = array_change_key_case(Txt::decode(file_get_contents($file->root() . '.txt')));
        foreach (['camera', 'lens', 'focal', 'aperture', 'shutter', 'iso'] as $field) {
            $this->assertArrayNotHasKey($field, $meta);
        }
    }
}
