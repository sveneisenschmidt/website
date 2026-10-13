<?php
/**
 * The front page: the newest pick large, the twelve before it as tiles,
 * the timeline next to them.
 *
 * @var Kirby\Cms\Page $page
 */
$picks = blog_picks();
$lead = $picks->first();
?>
<?php snippet('layout', slots: true) ?>
<section class="pictures" aria-label="Pictures">
<div class="stream-title stream-opening">
<h2>Featured photo</h2>
<a class="far" href="/archive/photos/">All photos</a>
</div>
<?php if ($lead): $cover = $lead->cover()->toFile(); $size = (clone $cover->dimensions())->fitWidth(1600); ?>
<article class="picture">
<h3><a href="<?= $lead->url() ?>"><?= $lead->title()->esc() ?></a></h3>
<p class="metadata">
<time datetime="<?= $lead->date()->toDate('Y-m-d') ?>"><?= blog_date($lead) ?></time>
</p>
<img
class="frame-picture"
style="view-transition-name: <?= blog_vt_name($lead) ?>"
src="<?= blog_wide($cover, 1600)->url() ?>"
srcset="<?= blog_wide($cover, 600)->url() ?> 600w, <?= blog_wide($cover, 1000)->url() ?> 1000w, <?= blog_wide($cover, 1600)->url() ?> 1600w"
sizes="(max-width: 900px) calc(100vw - 2rem), (max-width: 90rem) 55vw, 764px"
width="<?= $size->width() ?>"
height="<?= $size->height() ?>"
alt="<?= esc($cover->alt()->or($lead->title())->value()) ?>"
fetchpriority="high"
decoding="async"
>
</article>
<?php endif ?>
<?php if ($picks->count() > 1): ?>
<h2 class="stream-title">Previously featured</h2>
<ul class="tiles" role="list">
<?php foreach ($picks->offset(1)->limit(12) as $tile): $cover = $tile->cover()->toFile(); ?>
<li>
<a href="<?= $tile->url() ?>" aria-label="<?= $tile->title()->esc() ?>">
<img
class="tile"
style="view-transition-name: <?= blog_vt_name($tile) ?>"
src="<?= blog_square($cover, 480, 80)->url() ?>"
srcset="<?= blog_square($cover, 240, 80)->url() ?> 240w, <?= blog_square($cover, 480, 80)->url() ?> 480w"
sizes="(max-width: 900px) 25vw, 16vw"
width="480"
height="480"
alt=""
loading="lazy"
decoding="async"
>
</a>
</li>
<?php endforeach ?>
</ul>
<?php endif ?>
</section>
<?php snippet('stream', ['limit' => 30, 'fit' => true]) ?>
<?php endsnippet() ?>
