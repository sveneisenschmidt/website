<?php
use Website\Blog\Images;

/**
 * The front page: the newest pick large, the twelve before it as tiles,
 * the timeline next to them (controllers/home.php).
 *
 * @var Website\Models\PostPage|null $lead
 * @var Kirby\Cms\Pages<Website\Models\PostPage> $tiles
 */
?>
<?php snippet('layout', slots: true) ?>
<section class="pictures" aria-label="Pictures">
<div class="stream-title stream-opening">
<h2>Featured photo</h2>
<a class="far" href="/archive/photos/">All photos</a>
</div>
<?php if ($lead): $cover = $lead->coverImage();
    $picture = $lead->coverPicture(); ?>
<article class="picture">
<h3><a href="<?= $lead->url() ?>"><?= $lead->title()->esc() ?></a></h3>
<p class="metadata">
<time datetime="<?= $lead->date()->toDate('Y-m-d') ?>"><?= $lead->dateText() ?></time>
</p>
<img
class="frame-picture"
style="view-transition-name: <?= $lead->transitionName() ?>"
src="<?= $picture['src'] ?>"
srcset="<?= $picture['srcset'] ?>"
sizes="(max-width: 900px) calc(100vw - 2rem), (max-width: 90rem) 55vw, 764px"
width="<?= $picture['width'] ?>"
height="<?= $picture['height'] ?>"
alt="<?= esc($cover->alt()->or($lead->title())->value()) ?>"
fetchpriority="high"
decoding="async"
>
</article>
<?php endif ?>
<?php if ($tiles->isNotEmpty()): ?>
<h2 class="stream-title">Previously featured</h2>
<ul class="tiles" role="list">
<?php foreach ($tiles as $tile): $cover = $tile->coverImage(); ?>
<li>
<a href="<?= $tile->url() ?>" aria-label="<?= $tile->title()->esc() ?>">
<img
class="tile"
style="view-transition-name: <?= $tile->transitionName() ?>"
src="<?= Images::square($cover, 480, 80)->url() ?>"
srcset="<?= Images::square($cover, 240, 80)->url() ?> 240w, <?= Images::square($cover, 480, 80)->url() ?> 480w"
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
