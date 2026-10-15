<?php
use Website\Blog\Exif;
use Website\Blog\Images;
use Website\Models\PostPage;

/**
 * One image of a post, for the KirbyText tag (image:): the image as a link
 * to the original file, and below it the EXIF lines, the caption and the
 * photographer.
 *
 * @var Kirby\Cms\File $file
 * @var Website\Models\SitePage $page
 */
$picture = Images::picture($file);
$rows = Exif::rows($file);
$caption = (string) $file->caption()->value();
$photographer = (string) $file->photographer()->value();
// The first image of the post is at the top of the page, so the browser
// loads it at once and with high priority. The cover of a post gets the
// view-transition-name of its tile on the front page, so the browser
// animates it from the tile into the post. Only posts have tiles.
$first = $page->firstImageName() === $file->filename();
$cover = $page instanceof PostPage && $page->coverImage()?->is($file) === true;
// The HTML below must not contain a blank line. Markdown ends an HTML block
// at a blank line and would treat the rest as Markdown text.
?>
<figure>
<a class="frame" href="<?= $file->url() ?>">
<img
class="frame-picture"
<?php if ($cover): ?>style="view-transition-name: <?= $page->transitionName() ?>"
<?php endif ?>src="<?= $picture['src'] ?>"
srcset="<?= $picture['srcset'] ?>"
sizes="(max-width: 900px) calc(100vw - 2rem), (max-width: 90rem) 63vw, 907px"
width="<?= $picture['width'] ?>"
height="<?= $picture['height'] ?>"
alt="<?= $file->alt()->esc() ?>"
<?php if ($first): ?>loading="eager"
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
