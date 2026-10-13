<?php

namespace Website\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ExifTest extends TestCase
{
    public function testReadsCameraLensAndExposure(): void
    {
        $this->assertSame([
            'camera' => 'TEST-CAM',
            'lens' => 'Test Lens 24mm',
            'focal' => '24',
            'aperture' => '2.8',
            'shutter' => '1/250',
            'iso' => '3200',
        ], blog_read_exif(__DIR__ . '/../fixtures/exif.jpg'));
    }

    public function testCameraNamesTheMakeOnce(): void
    {
        $this->assertSame('Canon EOS R6m2', blog_camera('Canon', 'Canon EOS R6m2'));
        $this->assertSame('SONY ILCE-7M4', blog_camera('SONY', 'ILCE-7M4'));
        $this->assertSame('TEST-CAM', blog_camera('', 'TEST-CAM'));
    }

    public function testFileWithoutExifGivesNothing(): void
    {
        $path = sys_get_temp_dir() . '/website-noexif-' . bin2hex(random_bytes(4)) . '.png';
        imagepng(imagecreatetruecolor(4, 4), $path);
        try {
            $this->assertSame([], blog_read_exif($path));
        } finally {
            unlink($path);
        }
    }

    public function testFieldsFromExifSections(): void
    {
        // Canon EOS R5: ISO only in tag 0x8832, which PHP does not name.
        $this->assertSame(
            ['camera' => 'Canon EOS R5', 'focal' => '35', 'aperture' => '1.8', 'shutter' => '1/100', 'iso' => '12800'],
            blog_exif_fields([
                'IFD0' => ['Make' => 'Canon', 'Model' => 'Canon EOS R5'],
                'EXIF' => ['FocalLength' => '35/1', 'FNumber' => '9/5', 'ExposureTime' => '1/100', 'UndefinedTag:0x8832' => 12800],
            ]),
        );
    }

    public function testLeavesOutZeroAndBrokenValues(): void
    {
        $this->assertSame([], blog_exif_fields([
            'EXIF' => ['FocalLength' => '0/1', 'FNumber' => '0/0', 'ExposureTime' => '0/1', 'ISOSpeedRatings' => 0],
        ]));
    }

    public function testShutterSpeeds(): void
    {
        $this->assertSame('1/250', blog_shutter(1 / 250));
        $this->assertSame('1/3', blog_shutter(1 / 3));
        $this->assertSame('0.8', blog_shutter(0.8));
        $this->assertSame('2.5', blog_shutter(2.5));
        $this->assertSame('30', blog_shutter(30.0));
    }
}
