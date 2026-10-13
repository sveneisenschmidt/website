<?php
use Website\Blog\Site;

/**
 * The About page: its label, the date of the last check, the text, the
 * visitor statistics, a mail link and the timeline.
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
<?php if (Site::email() !== ''): ?>
<p class="post-footer"><a href="mailto:<?= esc(Site::email()) ?>?subject=<?= rawurlencode('Re: ' . $page->title()->value()) ?>">Leave me a message</a>
</p>
<?php endif ?>
</article>
<?php snippet('stream', ['current' => $page]) ?>
<?php endsnippet() ?>
