<?php
use Website\Blog\Images;
use Website\Blog\Posts;

/**
 * Posts by month, for the archive and the search results: a heading with
 * month and count, then a grid of covers ($sheet) or one row per post with
 * day, title and cover.
 *
 * @var Kirby\Cms\Pages<Website\Models\PostPage> $posts Newest first
 * @var bool|null $sheet True for the grid of covers, false when left out
 */
$sheet ??= false;
?>
<?php foreach (Posts::months($posts) as $month => $group): ?>
<h2 class="archive-month stream-title">
<span><?= $month ?></span>
<span><?= count($group) ?></span>
</h2>
<?php if ($sheet): ?>
<ul class="archive-sheet">
<?php foreach ($group as $post): if ($cover = $post->coverImage()): $square = Images::tile($cover, 'archive'); ?>
<li>
<a href="<?= $post->url() ?>" aria-label="<?= $post->title()->esc() ?>">
<img
class="tile"
src="<?= $square['src'] ?>"
srcset="<?= $square['srcset'] ?>"
sizes="(max-width: 900px) 45vw, (max-width: 1200px) 200px, 170px"
width="<?= $square['edge'] ?>"
height="<?= $square['edge'] ?>"
alt=""
loading="lazy"
decoding="async"
>
<?php if ($post->pick()->toBool()): ?><span class="tile-pick"><?php snippet('icon-heart') ?></span><?php endif ?>
</a>
</li>
<?php endif; endforeach ?>
</ul>
<?php else: ?>
<ul class="archive-list">
<?php foreach ($group as $post): ?>
<li class="archive-item">
<span class="archive-day"><time datetime="<?= $post->date()->toDate('Y-m-d') ?>"><?= $post->date()->toDate('j') ?></time></span>
<a class="archive-title" href="<?= $post->url() ?>"><?= $post->title()->esc() ?></a>
<?php snippet('thumb', ['post' => $post]) ?>
</li>
<?php endforeach ?>
</ul>
<?php endif ?>
<?php endforeach ?>
