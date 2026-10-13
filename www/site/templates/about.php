<?php
/**
 * About, as Hugo's page.html for a page that is no post: its label, the
 * date it was last checked, the text, the visitor numbers.
 *
 * @var Kirby\Cms\Page $page
 */
// The visitor numbers change all day: this page never goes into the cache.
kirby()->response()->cache(false);
?>
<?php snippet('layout', slots: true) ?>
<article class="article">
<div class="article-head">
<p class="stream-title stream-opening"><?= $page->label()->esc() ?>
<a class="far" href="/" data-back>Back</a>
</p>
<h1><?= $page->title()->esc() ?></h1>
<p class="metadata">
Last updated: <time datetime="<?= date('Y-m-d', blog_modified($page)) ?>"><?= date('F j, Y', blog_modified($page)) ?></time>
</p>
</div>
<div class="body">
<?= blog_html($page->text()->value(), $page) ?>
<?php snippet('stats') ?>
</div>
<p class="post-footer"><a href="mailto:<?= BLOG_SITE['email'] ?>?subject=<?= rawurlencode('Re: ' . $page->title()->value()) ?>">Leave me a message</a>
</p>
</article>
<?php snippet('stream', ['current' => $page]) ?>
<?php endsnippet() ?>
