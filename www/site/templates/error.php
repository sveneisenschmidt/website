<?php
/**
 * The error page.
 *
 * @var Kirby\Cms\Page $page
 */
?>
<?php snippet('layout', slots: true) ?>
<article class="article">
<p class="stream-title stream-opening">Error 404</p>
<h1>Page not found</h1>
<div class="body">
<p>This page doesn't exist, or it moved. <a href="/">The front page</a> and <a href="/archive/">the archive</a> have everything that does.</p>
</div>
</article>
<?php endsnippet() ?>
