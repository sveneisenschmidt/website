<?php
/**
 * An image in a text without a figure and without EXIF lines: the tag
 * (image: … imgclass: …), as on About. The line end after the
 * element ends its HTML block (`Website\Blog\Tags`).
 *
 * @var Kirby\Cms\File $file
 * @var string $class
 */
?>
<img src="<?= esc($file->url()) ?>" alt="<?= $file->alt()->esc() ?>" class="<?= esc($class) ?>">
