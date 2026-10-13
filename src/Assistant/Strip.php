<?php

namespace Website\Assistant;

use RuntimeException;

/**
 * The metadata filters of the uploads: a JPEG, PNG or WebP keeps only what
 * draws the image (security review, 2026-10-12). Each works on the bytes,
 * lossless, and throws RuntimeException on a broken file.
 */
final class Strip
{
    /**
     * The segments of a JPEG that stay: what draws the image (SOFn, DHT,
     * DAC, DQT, DRI, SOS with its scan data and RSTn), and APP0 JFIF, APP2
     * ICC_PROFILE and APP14 Adobe for its colours. Everything else goes:
     * EXIF and XMP, IPTC, comments, C2PA in APP11, MPF, thumbnails, segments
     * not known today.
     */
    public const JPEG_KEEP = [0xC0, 0xC1, 0xC2, 0xC3, 0xC4, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCC, 0xCD, 0xCE, 0xCF, 0xDB, 0xDD];
    public const JPEG_APP_KEEP = [0xE0 => "JFIF\x00", 0xE2 => "ICC_PROFILE\x00", 0xEE => 'Adobe'];

    /** The chunks of a PNG that stay: what draws the image, its colours, its size and its animation. */
    public const PNG_KEEP = ['IHDR', 'PLTE', 'IDAT', 'IEND', 'tRNS', 'gAMA', 'cHRM', 'sRGB', 'iCCP', 'cICP', 'sBIT', 'pHYs', 'bKGD', 'acTL', 'fcTL', 'fdAT'];

    /** The chunks of a WebP that stay: the image, its alpha, its animation and its colour profile. */
    public const WEBP_KEEP = ['VP8 ', 'VP8L', 'VP8X', 'ALPH', 'ANIM', 'ANMF', 'ICCP'];

    /**
     * A JPEG with only the segments of JPEG_KEEP, lossless: every scan of a
     * progressive JPEG stays, what lies between them is checked as well, and
     * whatever follows the end of the image goes (motion photos, gain maps).
     * An orientation other than 1 comes back as an EXIF segment with only
     * that tag.
     */
    public static function jpeg(string $jpeg, int $orientation = 1): string
    {
        if (!str_starts_with($jpeg, "\xFF\xD8")) {
            throw new RuntimeException('Not a JPEG');
        }
        $out = "\xFF\xD8";
        if ($orientation >= 2 && $orientation <= 8) {
            // TIFF, big endian: one IFD with one entry, Orientation (0x0112, SHORT).
            $tiff = "MM\x00\x2A\x00\x00\x00\x08\x00\x01" . pack('nnNn', 0x0112, 3, 1, $orientation) . "\x00\x00\x00\x00\x00\x00";
            $data = "Exif\x00\x00" . $tiff;
            $out .= "\xFF\xE1" . pack('n', strlen($data) + 2) . $data;
        }
        $pos = 2;
        $length = strlen($jpeg);
        $scans = 0;
        while ($pos + 2 <= $length) {
            if ($jpeg[$pos] !== "\xFF") {
                throw new RuntimeException('Broken JPEG');
            }
            $marker = ord($jpeg[$pos + 1]);
            if ($marker === 0xFF) {
                $pos++;
                continue;
            }
            if ($marker === 0xD9) {
                if ($scans === 0) {
                    throw new RuntimeException('JPEG without image data');
                }
                return $out . "\xFF\xD9";
            }
            if ($pos + 4 > $length) {
                throw new RuntimeException('Broken JPEG');
            }
            $size = unpack('n', substr($jpeg, $pos + 2, 2))[1];
            if ($size < 2 || $pos + 2 + $size > $length) {
                throw new RuntimeException('Broken JPEG');
            }
            $segment = substr($jpeg, $pos, 2 + $size);
            $pos += 2 + $size;
            if ($marker === 0xDA) {
                // Start of scan: its data runs to the next marker that is not a
                // stuffed 0xFF00, a fill byte or a restart marker RSTn.
                $end = $pos;
                while (($end = strpos($jpeg, "\xFF", $end)) !== false && $end + 1 < $length) {
                    $next = ord($jpeg[$end + 1]);
                    if ($next !== 0x00 && $next !== 0xFF && ($next < 0xD0 || $next > 0xD7)) {
                        break;
                    }
                    $end++;
                }
                if ($end === false || $end + 1 >= $length) {
                    throw new RuntimeException('JPEG without end');
                }
                $out .= $segment . substr($jpeg, $pos, $end - $pos);
                $pos = $end;
                $scans++;
                continue;
            }
            $app = self::JPEG_APP_KEEP[$marker] ?? null;
            if (in_array($marker, self::JPEG_KEEP, true) || ($app !== null && substr($segment, 4, strlen($app)) === $app)) {
                $out .= $segment;
            }
        }
        throw new RuntimeException('JPEG without end');
    }

    /** A PNG with only the chunks of PNG_KEEP, lossless: text, EXIF, time, C2PA (caBX) and unknown chunks go. */
    public static function png(string $png): string
    {
        $signature = "\x89PNG\r\n\x1a\n";
        if (!str_starts_with($png, $signature)) {
            throw new RuntimeException('Not a PNG');
        }
        $out = $signature;
        $pos = 8;
        $length = strlen($png);
        while ($pos + 12 <= $length) {
            $size = unpack('N', substr($png, $pos, 4))[1];
            $type = substr($png, $pos + 4, 4);
            if ($pos + 12 + $size > $length) {
                throw new RuntimeException('Broken PNG');
            }
            if (in_array($type, self::PNG_KEEP, true)) {
                $out .= substr($png, $pos, 12 + $size);
            }
            $pos += 12 + $size;
            if ($type === 'IEND') {
                return $out;
            }
        }
        throw new RuntimeException('PNG without end');
    }

    /** A WebP with only the chunks of WEBP_KEEP, lossless: EXIF, XMP and unknown chunks go. */
    public static function webp(string $webp): string
    {
        if (substr($webp, 0, 4) !== 'RIFF' || substr($webp, 8, 4) !== 'WEBP') {
            throw new RuntimeException('Not a WebP');
        }
        $end = min(strlen($webp), 8 + unpack('V', substr($webp, 4, 4))[1]);
        $body = '';
        $pos = 12;
        while ($pos + 8 <= $end) {
            $type = substr($webp, $pos, 4);
            $size = unpack('V', substr($webp, $pos + 4, 4))[1];
            if ($pos + 8 + $size > $end) {
                throw new RuntimeException('Broken WebP');
            }
            $chunk = substr($webp, $pos, 8 + $size + ($size & 1));
            if ($type === 'VP8X' && $size >= 1) {
                // The flags of the extended format: ICC (0x20), alpha (0x10) and
                // animation (0x02) stay; EXIF (0x08), XMP (0x04) and the
                // reserved bits go with their chunks.
                $chunk[8] = chr(ord($chunk[8]) & 0x32);
            }
            if (in_array($type, self::WEBP_KEEP, true)) {
                $body .= $chunk;
            }
            $pos += 8 + $size + ($size & 1);
        }
        return 'RIFF' . pack('V', 4 + strlen($body)) . 'WEBP' . $body;
    }
}
