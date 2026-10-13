<?php
use Website\Blog\Images;
use Website\Blog\Posts;

/**
 * Posts by month, for the archive and the search results: a heading with
 * month and count, then a grid of covers ($sheet) or one row per post with
 * day, title and cover.
 *
 * @var Kirby\Cms\Pages<Website\Models\PostPage> $posts Newest first
 * @var bool $sheet True for the grid of covers
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
<?php foreach ($group as $post): if ($cover = $post->coverImage()): ?>
<li>
<a href="<?= $post->url() ?>" aria-label="<?= $post->title()->esc() ?>">
<img
class="tile"
src="<?= Images::square($cover, 400, 85)->url() ?>"
srcset="<?= Images::square($cover, 200, 85)->url() ?> 200w, <?= Images::square($cover, 400, 85)->url() ?> 400w"
sizes="(max-width: 900px) 45vw, (max-width: 1200px) 200px, 170px"
width="400"
height="400"
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
