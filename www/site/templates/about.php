<?php
use Website\Blog\Site;

/**
 * About: its label, the date it was last checked, the text, the visitor
 * numbers. It stays out of the page cache (config.php).
 *
 * @var Website\Models\SitePage $page
 */
?>
<?php snippet('layout', slots: true) ?>
<article class="article">
<div class="article-head">
<p class="stream-title stream-opening"><?= $page->label()->esc() ?>
<a class="far" href="/" data-back>Back</a>
</p>
<h1><?= $page->title()->esc() ?></h1>
<p class="metadata">
Last updated: <time datetime="<?= date('Y-m-d', $page->lastModified()) ?>"><?= date('F j, Y', $page->lastModified()) ?></time>
</p>
</div>
<div class="body">
<?= $page->bodyHtml() ?>
<?php snippet('stats') ?>
</div>
<p class="post-footer"><a href="mailto:<?= Site::EMAIL ?>?subject=<?= rawurlencode('Re: ' . $page->title()->value()) ?>">Leave me a message</a>
</p>
</article>
<?php snippet('stream', ['current' => $page]) ?>
<?php endsnippet() ?>
