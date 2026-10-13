<?php

namespace Website\Blog;

use Kirby\Filesystem\Dir;
use RuntimeException;

/**
 * JSON into a temporary file first, then renamed: a crash or a full disk
 * never leaves half a file. For the redirects and the visitor numbers
 * (plugins/analytics).
 */
final class JsonFile
{
    /** @param array<mixed> $data */
    public static function write(string $file, array $data): void
    {
        Dir::make(dirname($file));
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($tmp, $json) !== strlen($json) || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException("Could not write $file");
        }
    }
}
