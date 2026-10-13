<?php
/**
 * An image of a post: linked to the original, 600, 1000 and 1600 px WebP,
 * the EXIF rows below. No blank lines: Markdown would end the HTML block
 * there.
 *
 * @var Kirby\Cms\File $file
 * @var Kirby\Cms\Page $page
 */
$picture = blog_picture($file);
$rows = blog_exif_rows($file);
$caption = (string)$file->caption()->value();
$photographer = (string)$file->photographer()->value();
// The cover is the picture under the title: the browser loads it first.
$cover = $page->cover()->toFile()?->is($file) === true;
?>
<figure>
<a class="frame" href="<?= $file->url() ?>">
<img
class="frame-picture"
<?php if ($cover): ?>style="view-transition-name: <?= blog_vt_name($page) ?>"
<?php endif ?>src="<?= $picture['src'] ?>"
srcset="<?= $picture['srcset'] ?>"
sizes="(max-width: 900px) calc(100vw - 2rem), (max-width: 90rem) 63vw, 907px"
width="<?= $picture['width'] ?>"
height="<?= $picture['height'] ?>"
alt="<?= $file->alt()->esc() ?>"
<?php if ($cover): ?>loading="eager"
fetchpriority="high"
<?php else: ?>loading="lazy"
<?php endif ?>decoding="async"
/>
</a><?php if ($rows !== [] || $caption !== '' || $photographer !== ''): ?><dl class="exif">
<?php foreach ($rows as $key => $value): ?>
<div>
<dt><?= $key ?></dt>
<dd><?= esc($value) ?></dd>
</div>
<?php endforeach ?>
<?php if ($caption !== ''): ?>
<div class="caption<?= strlen($caption) > 40 ? ' caption-long' : '' ?>">
<dt>Caption</dt>
<dd><?= kirby()->kirbytext($caption, ['markdown' => ['inline' => true]]) ?></dd>
</div>
<?php endif ?>
<?php if ($photographer !== ''): ?>
<div class="photographer">
<dt>Photographer</dt>
<dd><?= esc($photographer) ?></dd>
</div>
<?php endif ?>
</dl><?php endif ?></figure>
