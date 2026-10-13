<?php
/**
 * The archive and its sub-pages: the filter row and the posts by month, on
 * the photos page as a grid of covers. Variables from controllers/archive.php.
 *
 * @var string $stream all, photos, trips, notes or essays
 * @var Kirby\Cms\Pages $posts The posts that this page lists
 */
?>
<?php snippet('layout', slots: true) ?>
<section class="archive">
<?php snippet('stream-filter', ['current' => $stream]) ?>
<h1 class="archive-heading">Archive</h1>
<?php snippet('months', ['posts' => $posts, 'sheet' => $stream === 'photos']) ?>
</section>
<?php endsnippet() ?>
