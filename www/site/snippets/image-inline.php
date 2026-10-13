<?php
/**
 * An image in a text without a figure and without EXIF lines: the tag
 * (image: … imgclass: …), as on About. `Website\Blog\Tags::image()`
 * removes the line end after the element.
 *
 * @var Kirby\Cms\File $file
 * @var string $class
 */
?>
<img src="<?= esc($file->url()) ?>" alt="<?= $file->alt()->esc() ?>" class="<?= esc($class) ?>">
