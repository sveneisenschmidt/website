<?php
/**
 * The archive and its four streams (controllers/archive.php).
 *
 * @var string $stream all, photos, trips, notes or essays
 * @var Kirby\Cms\Pages $posts The posts of the stream
 */
?>
<?php snippet('layout', slots: true) ?>
<section class="archive">
<?php snippet('stream-filter', ['current' => $stream]) ?>
<h1 class="archive-heading">Archive</h1>
<?php snippet('months', ['posts' => $posts, 'sheet' => $stream === 'photos']) ?>
</section>
<?php endsnippet() ?>
