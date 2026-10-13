<?php
/**
 * Any other page: its title in the frame of the site. The section pages
 * photos, trips, notes and essays only order the posts; their URLs lead
 * to the archive (data/redirects.yaml).
 *
 * @var Kirby\Cms\Page $page
 */
?>
<?php snippet('layout', slots: true) ?>
<article class="article">
<h1><?= $page->title()->esc() ?></h1>
</article>
<?php endsnippet() ?>
