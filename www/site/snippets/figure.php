<?php
/**
 * An image of a post, as Hugo's img shortcode drew it: linked to the
 * original, 600, 1000 and 1600 px WebP, the EXIF rows below. No blank
 * lines: Markdown would end the HTML block there.
 *
 * @var Kirby\Cms\File $file
 * @var Kirby\Cms\Page $page
 */
$wide = blog_wide($file, 1600);
$mid = blog_wide($file, 1000);
$narrow = blog_wide($file, 600);
[$width, $height] = blog_wide_size($file, 1600);
$rows = blog_exif_rows($file);
$caption = (string)$file->caption()->value();
$photographer = (string)$file->photographer()->value();
?>
<figure>
<a class="frame" href="<?= $file->url() ?>">
<img
class="frame-picture"
<?php if ($page->cover()->toFile()?->is($file)): ?>style="view-transition-name: <?= blog_vt_name($page) ?>"
<?php endif ?>src="<?= $wide->url() ?>"
srcset="<?= $narrow->url() ?> 600w, <?= $mid->url() ?> 1000w, <?= $wide->url() ?> 1600w"
sizes="(max-width: 900px) calc(100vw - 2rem), (max-width: 90rem) 63vw, 907px"
width="<?= $width ?>"
height="<?= $height ?>"
alt="<?= $file->alt()->esc() ?>"
loading="lazy"
decoding="async"
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
