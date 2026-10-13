<?php
use Website\Blog\Posts;

/**
 * The timeline: a post shows its two neighbours, every other page the
 * newest $limit posts with their count.
 *
 * @var Kirby\Cms\Page|null $current The page the timeline stands beside
 * @var int $limit
 * @var bool $fit The list ends with the pictures next to it (fit.js)
 */
$current ??= null;
$limit ??= 25;
$fit ??= false;
['neighbours' => $neighbours, 'entries' => $entries] = Posts::timeline($current, $limit);
?>
<section class="writing<?= $current ? ' writing-aside' : '' ?>" aria-label="Posts"<?= $fit ? ' data-fit=".pictures"' : '' ?>>
<div class="writing-inner">
<div class="stream-title stream-opening">
<h2>Timeline<?php if (!$neighbours): ?> <span class="stream-count"><?= count($entries) ?></span><?php endif ?></h2>
<nav class="far" aria-label="Site">
<a href="/archive/">Archive</a>
</nav>
</div>
<div class="writing-list">
<?php foreach ($entries as $i => [$post, $label]): ?>
<article class="<?= $i === 0 ? 'writing-lead' : 'writing-item' ?><?= $post->cover()->isNotEmpty() ? ' has-thumb' : '' ?>" data-type="<?= $post->intendedTemplate()->name() ?>">
<div>
<h3><a href="<?= $post->url() ?>"><?php if ($label !== ''): ?><span class="writing-label"><?= $label ?>:</span> <?php endif ?><?= $post->title()->esc() ?></a></h3>
<p class="metadata">
<time datetime="<?= $post->date()->toDate('Y-m-d') ?>"><?= $post->dateText() ?></time>
<?php if (($type = $post->typeLine()) !== ''): ?>&middot; <?= esc($type) ?><?php endif ?>
</p>
</div>
<?php snippet('thumb', ['post' => $post]) ?>
<?php if ($i === 0 && !$neighbours && ($excerpt = $post->summary()) !== ''): ?><p class="excerpt"><?= esc($excerpt) ?></p><?php endif ?>
</article>
<?php endforeach ?>
</div>
</div>
</section>
