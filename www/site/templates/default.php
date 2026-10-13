<?php
/**
 * A page without its own template: only its title. The parent pages
 * photos, trips, notes and essays use it, but a redirect sends their URLs
 * to the archive (content/.redirects.json).
 *
 * @var Kirby\Cms\Page $page
 */
?>
<?php snippet('layout', slots: true) ?>
<article class="article">
<h1><?= $page->title()->esc() ?></h1>
</article>
<?php endsnippet() ?>
