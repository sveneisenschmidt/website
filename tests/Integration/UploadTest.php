<?php

namespace Website\Tests\Integration;

use BlogAssistantError;
use Website\Tests\Support\GpxFixture;

/**
 * uploads.php: what the chat accepts, what it keeps and when it deletes
 * (spec, "Uploads" and "GPX und Svens Zuhause").
 */
final class UploadTest extends AssistantTestCase
{
    private function refused(string $path, string $name, string $reason): void
    {
        try {
            blog_uploads_accept($path, $name);
            $this->fail("Accepted: $name");
        } catch (BlogAssistantError $e) {
            $this->assertSame('upload', $e->kind);
            $this->assertStringContainsString($reason, $e->getMessage(), $name);
        }
    }

    /** Files with an upload id in the storage folder. */
    private function stored(): array
    {
        return glob($this->root . '/storage/assistant/*/u*') ?: [];
    }

    public function testAJpegKeepsItsCameraDataAndLosesGps(): void
    {
        $this->assertArrayHasKey('GPS', exif_read_data($this->gpsJpeg(6), null, true));

        $upload = blog_uploads_accept($this->gpsJpeg(6), 'IMG_0001.JPG');

        $meta = blog_uploads_meta($upload['id']);
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
        $this->assertSame([['id' => $upload['id'], 'name' => 'img_0001.jpg', 'kind' => 'image']], blog_uploads_pending());
        $preview = dirname($meta['path']) . '/' . $upload['id'] . '.preview.jpg';
        $this->assertSame([48, 64], array_slice(getimagesize($preview), 0, 2));
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
        $this->assertSame([], $this->stored());
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
        $png = (string)ob_get_clean();
        $text = "Comment\x00GPS 51.3";
        $chunk = pack('N', strlen($text)) . 'tEXt' . $text . pack('N', crc32('tEXt' . $text));
        file_put_contents($this->root . '/a.png', substr($png, 0, -12) . $chunk . substr($png, -12));
        imagewebp(imagecreatetruecolor(10, 10), $this->root . '/b.webp');

        $png = blog_uploads_meta(blog_uploads_accept($this->root . '/a.png', 'a.png')['id']);
        $webp = blog_uploads_meta(blog_uploads_accept($this->root . '/b.webp', 'b.webp')['id']);

        $this->assertStringNotContainsString('GPS 51.3', file_get_contents($png['path']));
        $this->assertSame('a.png', $png['name']);
        $this->assertNotFalse(imagecreatefrompng($png['path']));
        $this->assertNotFalse(imagecreatefromwebp($webp['path']));
    }

    public function testForeignUploadIdsAreRefused(): void
    {
        $id = blog_uploads_accept($this->jpeg(8, 8), 'a.jpg')['id'];
        $this->assertNotNull(blog_uploads_meta($id));
        $this->assertNull(blog_uploads_meta('../../site/config/env'));
        $this->assertNull(blog_uploads_meta('u0000000000000000'));
        $this->assertNull(blog_uploads_read('../' . $id . '.jpg'));

        blog_uploads_reset();

        $this->assertNull(blog_uploads_meta($id), 'The id belonged to the chat before.');
    }

    public function testAGpxIsReadDeletedAndCut(): void
    {
        $path = GpxFixture::write($this->root, GpxFixture::xml(GpxFixture::ride()));

        $upload = blog_uploads_accept($path, 'Morning Ride.gpx');

        $this->assertFileDoesNotExist($path);
        $meta = blog_uploads_meta($upload['id']);
        $this->assertSame('gpx', $meta['kind']);
        $this->assertNull($meta['path']);
        $this->assertEqualsWithDelta(13.8, $meta['km'], 0.6);
        foreach ($meta['line'] as $point) {
            $this->assertGreaterThan(650, blog_route_distance(GpxFixture::home(), $point));
        }
        $stored = file_get_contents(dirname($this->stored()[0]) . '/' . $upload['id'] . '.json');
        foreach (['Test Device', 'Morning ride', '2026-', '<hr>', 'ele'] as $leak) {
            $this->assertStringNotContainsString($leak, $stored);
        }
        $text = blog_uploads_blocks($upload['id'])[0]['text'];
        $this->assertStringContainsString('both ends cut', $text);
        $this->assertStringNotContainsString(json_encode(array_map(fn ($v) => round($v, 5), GpxFixture::home())), $text);
    }

    public function testAGpxWithADoctypeIsRefusedAndAShortOneExplained(): void
    {
        $path = GpxFixture::write($this->root, '<?xml version="1.0"?><!DOCTYPE gpx [<!ENTITY x SYSTEM "file:///etc/passwd">]><gpx>&x;</gpx>');
        $this->refused($path, 'evil.gpx', 'DOCTYPE');
        $this->assertFileDoesNotExist($path);

        $short = blog_uploads_accept(GpxFixture::write($this->root, GpxFixture::xml(GpxFixture::ride(400))), 'short.gpx');

        $this->assertStringContainsString('shorter than 3 km', blog_uploads_blocks($short['id'])[0]['text']);
    }

    public function testOldFoldersAndUploadsAreCleanedUp(): void
    {
        $id = blog_uploads_accept($this->jpeg(8, 8), 'a.jpg')['id'];
        $dir = dirname(blog_uploads_meta($id)['path']);
        $old = $this->root . '/storage/assistant/' . str_repeat('a', 32);
        $recent = $this->root . '/storage/assistant/' . str_repeat('b', 32);
        foreach ([$old => 8, $recent => 6] as $folder => $days) {
            mkdir($folder);
            touch($folder . '/.last', time() - $days * 86400);
        }

        blog_uploads_cleanup();

        $this->assertDirectoryDoesNotExist($old);
        $this->assertDirectoryExists($recent);
        $this->assertNotNull(blog_uploads_meta($id)['path']);

        $meta = json_decode(file_get_contents("$dir/$id.json"), true);
        file_put_contents("$dir/$id.json", json_encode(['time' => time() - 8 * 86400] + $meta));

        blog_uploads_cleanup();

        $this->assertSame([], glob("$dir/$id.*"));
        $this->assertFileExists($this->root . '/storage/assistant/.htaccess');
    }
}
