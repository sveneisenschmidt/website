<?php

namespace Website\Tests\Integration;

use RuntimeException;
use Website\Assistant\Chat;
use Website\Assistant\Error;
use Website\Assistant\Gpx;
use Website\Assistant\Route;
use Website\Assistant\Session;
use Website\Assistant\Uploads;
use Website\Tests\Support\GpxFixture;

/**
 * Uploads: what the chat accepts, what it keeps and when it deletes.
 */
final class UploadTest extends AssistantTestCase
{
    private function refused(string $path, string $name, string $reason): void
    {
        try {
            Uploads::accept($path, $name);
            $this->fail("Accepted: $name");
        } catch (Error $e) {
            $this->assertSame('upload', $e->kind);
            $this->assertStringContainsString($reason, $e->getMessage(), $name);
        }
    }

    /**
     * Files with an upload id in the storage folder.
     *
     * @return array<mixed>
     */
    private function stored(): array
    {
        return glob($this->root . '/storage/assistant/*/u*') ?: [];
    }

    public function testAJpegKeepsItsCameraDataAndLosesGps(): void
    {
        $this->assertArrayHasKey('GPS', exif_read_data($this->gpsJpeg(6), null, true));

        $upload = Uploads::accept($this->gpsJpeg(6), 'IMG_0001.JPG');

        $meta = Uploads::meta($upload['id']);
        $this->assertMatchesRegularExpression('/^u[0-9a-f]{16}$/', $upload['id']);
        $this->assertSame('img_0001.jpg', $meta['name']);
        $this->assertSame(['camera' => 'GPS-CAM'], $meta['exif']);
        $this->assertSame([48, 64], [$meta['width'], $meta['height']], 'Orientation 6 stands upright.');
        $this->assertSame($this->root . '/storage/assistant', dirname($meta['path'], 2));
        $this->assertStringEndsWith($upload['id'] . '.jpg', $meta['path']);
        $exif = exif_read_data($meta['path'], null, true) ?: [];
        $this->assertArrayNotHasKey('GPS', $exif);
        $this->assertSame(6, $exif['IFD0']['Orientation']);
        $this->assertSame([64, 48], array_slice(getimagesize($meta['path']), 0, 2), 'The image itself is unchanged.');
        $this->assertSame([['id' => $upload['id'], 'name' => 'img_0001.jpg', 'kind' => 'image']], Uploads::pending());
        $preview = dirname($meta['path']) . '/' . $upload['id'] . '.preview.jpg';
        $this->assertSame([48, 64], array_slice(getimagesize($preview), 0, 2));
    }

    public function testALargePhotoGetsAScaledPreviewAndThumb(): void
    {
        $upload = Uploads::accept($this->gpsJpeg(1, 'big.jpg', 3200, 2000), 'big.jpg');

        $dir = dirname(Uploads::meta($upload['id'])['path']);
        $this->assertSame([1568, 980], array_slice(getimagesize("$dir/{$upload['id']}.preview.jpg"), 0, 2));
        $this->assertSame([240, 150], array_slice(getimagesize("$dir/{$upload['id']}.thumb.jpg"), 0, 2));
    }

    public function testAFailedWriteOfAPreviewIsAnError(): void
    {
        $source = $this->jpeg(40, 30);
        // GD cannot write where a folder stands; imagejpeg() then returns false.
        mkdir(Uploads::dir() . '/v0000000000000000.jpg');

        $this->expectException(RuntimeException::class);
        Uploads::image($source, 1, null, 20, 'v0000000000000000.jpg');
    }

    public function testExpiredUploadsAreNamedToClaude(): void
    {
        $id = Uploads::accept($this->jpeg(8, 8), 'a.jpg')['id'];
        $dir = dirname(Uploads::meta($id)['path']);
        $meta = json_decode(file_get_contents("$dir/$id.json"), true);
        file_put_contents("$dir/$id.json", json_encode(['time' => time() - 8 * 86400] + $meta));

        Uploads::cleanup();

        $this->assertSame([], Uploads::pending());
        $this->reply('end_turn');
        Chat::send('Hier ist das Foto.');
        $text = $this->lastText($this->requests[0]);
        $this->assertStringContainsString($id, $text);
        $this->assertStringContainsString('7 days', $text);
        $this->assertSame(['text'], array_column($this->requests[0]['messages'][0]['content'], 'type'));
    }

    public function testAnUploadHasItsOwnTimeLimit(): void
    {
        set_time_limit(0);
        try {
            Uploads::accept($this->jpeg(8, 8), 'a.jpg');
            $this->assertSame('60', ini_get('max_execution_time'));
        } finally {
            set_time_limit(0);
        }
    }

    /**
     * The probe up4.php of the security review: a camera name in Latin-1,
     * not UTF-8. It broke the JSON of the upload after its files were
     * written; they stayed behind.
     */
    public function testACameraNameInAnotherEncodingBreaksNothing(): void
    {
        ob_start();
        imagejpeg(imagecreatetruecolor(64, 48), null, 90);
        $plain = (string) ob_get_clean();
        $model = "Caf\xE9 Cam\x00";
        $tiff = "MM\x00\x2A" . pack('N', 8) . pack('n', 1) . pack('nnNN', 0x0110, 2, strlen($model), 26) . pack('N', 0) . $model;
        $app1 = "Exif\x00\x00" . $tiff;
        file_put_contents($this->root . '/latin1.jpg', "\xFF\xD8\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($plain, 2));

        $upload = Uploads::accept($this->root . '/latin1.jpg', 'a.jpg');

        $meta = Uploads::meta($upload['id']);
        $this->assertSame("Caf\u{FFFD} Cam", $meta['exif']['camera']);
        $this->assertEqualsCanonicalizing(['.json', '.jpg', '.preview.jpg', '.thumb.jpg'], array_map(fn($f) => substr(basename($f), 17), $this->stored()));
        $this->assertNotFalse(json_encode(Chat::view(Session::load())));
    }

    public function testCodeAndOtherTypesAreRefused(): void
    {
        file_put_contents($this->root . '/shell.php', '<?php system($_GET["c"]);');
        $this->refused($this->root . '/shell.php', 'shell.php', 'Nur JPEG');
        $this->refused($this->root . '/shell.php', 'photo.jpg', 'Nur JPEG');
        file_put_contents($this->root . '/.htaccess', "Require all granted\n");
        $this->refused($this->root . '/.htaccess', '.htaccess', 'Nur JPEG');
        file_put_contents($this->root . '/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->refused($this->root . '/logo.svg', 'logo.svg', 'Nur JPEG');
        $this->refused($this->gpsJpeg(1, 'poly.jpg', 64, 48, true), 'poly.jpg', 'Code');
        // PHP's short echo tag, in a comment segment (probe up1.php of the security review).
        file_put_contents($this->root . '/short.jpg', "\xFF\xD8" . $this->segment(0xFE, '<?= `id` ?>') . substr(file_get_contents($this->jpeg(8, 8)), 2));
        $this->refused($this->root . '/short.jpg', 'short.jpg', 'Code');
        // The three bytes alone come by chance in large photos, a few times in 20 MB of noise.
        file_put_contents($this->root . '/chance.jpg', "\xFF\xD8" . $this->segment(0xFE, "<?=\x00\xFF\x13") . substr(file_get_contents($this->jpeg(8, 8)), 2));
        $this->assertSame('image', Uploads::accept($this->root . '/chance.jpg', 'chance.jpg')['kind']);
        $this->assertCount(4, $this->stored(), 'Only the upload by chance.');
    }

    public function testTooManyPixelsAreRefused(): void
    {
        // Only the header of a PNG with 9000 x 9000 pixels: enough for getimagesize().
        $ihdr = pack('NNCCCCC', 9000, 9000, 8, 2, 0, 0, 0);
        file_put_contents($this->root . '/big.png', "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr)));
        $this->refused($this->root . '/big.png', 'big.png', '64 Megapixel');
    }

    public function testPngAndWebpLoseTheirMetadata(): void
    {
        ob_start();
        imagepng(imagecreatetruecolor(10, 10));
        $png = (string) ob_get_clean();
        $text = "Comment\x00GPS 51.3";
        $chunk = pack('N', strlen($text)) . 'tEXt' . $text . pack('N', crc32('tEXt' . $text));
        file_put_contents($this->root . '/a.png', substr($png, 0, -12) . $chunk . substr($png, -12));
        imagewebp(imagecreatetruecolor(10, 10), $this->root . '/b.webp');

        $png = Uploads::meta(Uploads::accept($this->root . '/a.png', 'a.png')['id']);
        $webp = Uploads::meta(Uploads::accept($this->root . '/b.webp', 'b.webp')['id']);

        $this->assertStringNotContainsString('GPS 51.3', file_get_contents($png['path']));
        $this->assertSame('a.png', $png['name']);
        $this->assertNotFalse(imagecreatefrompng($png['path']));
        $this->assertNotFalse(imagecreatefromwebp($webp['path']));
    }

    /** A JPEG segment: marker and data. */
    private function segment(int $marker, string $data): string
    {
        return "\xFF" . chr($marker) . pack('n', strlen($data) + 2) . $data;
    }

    /**
     * The probe up1.php of the security review: metadata where a list of
     * what goes would miss it. Only what draws the image stays.
     */
    public function testOnlyWhatDrawsTheImageStays(): void
    {
        $image = imagecreatetruecolor(64, 48);
        imagefilledrectangle($image, 0, 0, 32, 48, imagecolorallocate($image, 255, 0, 0));
        $encode = function (callable $write) use ($image): string {
            ob_start();
            $write($image);
            return (string) ob_get_clean();
        };
        $plain = $encode(fn($i) => imagejpeg($i, null, 90));
        $png = $encode(fn($i) => imagepng($i));
        $webp = $encode(fn($i) => imagewebp($i));
        imageinterlace($image, true);
        $progressive = $encode(fn($i) => imagejpeg($i, null, 90));
        $second = strpos($progressive, "\xFF\xDA", strpos($progressive, "\xFF\xDA") + 2);
        $this->assertNotFalse($second, 'A progressive JPEG has more than one scan.');
        $chunk = 'GPS-LEAK c2pa jumb';
        $webp .= 'XTRA' . pack('V', 9) . "GPS-LEAK!\x00";
        $cases = [
            // C2PA in APP11, an address in a comment, data after the end.
            'c2pa.jpg' => "\xFF\xD8" . $this->segment(0xEB, "JP\x00\x00\x00\x00\x00\x01jumb c2pa GPS-LEAK 51.34N") . $this->segment(0xFE, 'GPS-LEAK Home Street 5') . substr($plain, 2) . 'GPS-LEAK',
            // XMP between the scans of a progressive JPEG.
            'scans.jpg' => substr($progressive, 0, $second) . $this->segment(0xE1, "http://ns.adobe.com/xap/1.0/\x00<x:xmpmeta>GPS-LEAK 51.34</x:xmpmeta>") . substr($progressive, $second),
            'cabx.png' => substr($png, 0, 33) . pack('N', strlen($chunk)) . 'caBX' . $chunk . pack('N', crc32('caBX' . $chunk)) . substr($png, 33),
            'chunk.webp' => substr_replace($webp, pack('V', strlen($webp) - 8), 4, 4),
        ];
        $stored = [];
        foreach ($cases as $name => $bytes) {
            file_put_contents($this->root . '/' . $name, $bytes);
            $stored[$name] = file_get_contents(Uploads::meta(Uploads::accept($this->root . '/' . $name, $name)['id'])['path']);

            $this->assertStringNotContainsString('GPS-LEAK', $stored[$name], $name);
            $this->assertSame([64, 48], array_slice(getimagesizefromstring($stored[$name]), 0, 2), $name);
            $this->assertInstanceOf(\GdImage::class, imagecreatefromstring($stored[$name]), $name);
        }
        $this->assertSame(substr_count($progressive, "\xFF\xDA"), substr_count($stored['scans.jpg'], "\xFF\xDA"), 'Every scan stays.');
    }

    public function testForeignUploadIdsAreRefused(): void
    {
        $id = Uploads::accept($this->jpeg(8, 8), 'a.jpg')['id'];
        $this->assertNotNull(Uploads::meta($id));
        $this->assertNull(Uploads::meta('../../site/config/env'));
        $this->assertNull(Uploads::meta('u0000000000000000'));
        $this->assertNull(Uploads::read('../' . $id . '.jpg'));

        Uploads::reset();

        $this->assertNull(Uploads::meta($id), 'The id belonged to the chat before.');
    }

    public function testAGpxIsReadDeletedAndCut(): void
    {
        $path = GpxFixture::write($this->root, GpxFixture::xml(GpxFixture::ride()));

        $upload = Uploads::accept($path, 'Morning Ride.gpx');

        $this->assertFileDoesNotExist($path);
        $meta = Uploads::meta($upload['id']);
        $this->assertSame('gpx', $meta['kind']);
        $this->assertNull($meta['path']);
        $this->assertEqualsWithDelta(13.8, $meta['km'], 0.6);
        foreach ($meta['line'] as $point) {
            $this->assertGreaterThanOrEqual(Gpx::TRIM_MIN, Route::distance(GpxFixture::home(), $point));
        }
        $stored = file_get_contents(dirname($this->stored()[0]) . '/' . $upload['id'] . '.json');
        foreach (['Test Device', 'Morning ride', '2026-', '<hr>', 'ele'] as $leak) {
            $this->assertStringNotContainsString($leak, $stored);
        }
        $text = Uploads::blocks($upload['id'])[0]['text'];
        $this->assertStringContainsString('both ends cut', $text);
        $this->assertStringNotContainsString('morning', strtolower($text), 'A file name can name a place; Claude gets none.');
        $this->assertStringNotContainsString(json_encode(array_map(fn($v) => round($v, 5), GpxFixture::home())), $text);
    }

    public function testAGpxWithADoctypeIsRefusedAndAShortOneExplained(): void
    {
        $path = GpxFixture::write($this->root, '<?xml version="1.0"?><!DOCTYPE gpx [<!ENTITY x SYSTEM "file:///etc/passwd">]><gpx>&x;</gpx>');
        $this->refused($path, 'evil.gpx', 'DOCTYPE');
        $this->assertFileDoesNotExist($path);

        $short = Uploads::accept(GpxFixture::write($this->root, GpxFixture::xml(GpxFixture::oneWay(4000))), 'short.gpx');

        $this->assertStringContainsString('shorter than 3 km', Uploads::blocks($short['id'])[0]['text']);
    }

    public function testOldFoldersAndUploadsAreCleanedUp(): void
    {
        $id = Uploads::accept($this->jpeg(8, 8), 'a.jpg')['id'];
        $dir = dirname(Uploads::meta($id)['path']);
        $old = $this->root . '/storage/assistant/' . str_repeat('a', 32);
        $recent = $this->root . '/storage/assistant/' . str_repeat('b', 32);
        // Not a folder of a chat: the cleanup leaves it alone, however old.
        $other = $this->root . '/storage/assistant/backup';
        foreach ([$old => 8, $recent => 6, $other => 30] as $folder => $days) {
            mkdir($folder);
            touch($folder . '/.last', time() - $days * 86400);
        }

        Uploads::cleanup();

        $this->assertDirectoryDoesNotExist($old);
        $this->assertDirectoryExists($recent);
        $this->assertDirectoryExists($other);
        $this->assertNotNull(Uploads::meta($id)['path']);

        $meta = json_decode(file_get_contents("$dir/$id.json"), true);
        file_put_contents("$dir/$id.json", json_encode(['time' => time() - 8 * 86400] + $meta));

        Uploads::cleanup();

        $this->assertSame([], glob("$dir/$id.*"));
    }
}
