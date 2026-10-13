<?php
/**
 * A post of any type: head with type and date, the text, the mail link,
 * the neighbours in the timeline.
 *
 * @var Kirby\Cms\Page $page
 * @var Kirby\Cms\Site $site
 */
$section = $page->parent()->slug();
$type = blog_type_line($page, false);
?>
<?php snippet('layout', slots: true) ?>
<article class="article">
<div class="article-head">
<p class="stream-title stream-opening"><a href="/archive/<?= $section ?>/"><?= $page->pick()->toBool() ? 'Featured photo' : $page->intendedTemplate()->name() ?></a>
<a class="far" href="/" data-back>Back</a>
</p>
<h1><?= $page->title()->esc() ?></h1>
<p class="metadata">
<a href="/archive/"><time datetime="<?= $page->date()->toDate('Y-m-d') ?>"><?= blog_date($page) ?></time></a>
<?php if ($type !== ''): ?>&middot; <a href="/archive/<?= $section ?>/"><?= esc($type) ?></a><?php endif ?>
</p>
</div>
<div class="body">
<?= blog_content($page) ?>
</div>
<p class="post-footer"><a href="mailto:<?= BLOG_SITE['email'] ?>?subject=<?= rawurlencode('Re: ' . $page->title()->value()) ?>">Leave me a message</a>
</p>
</article>
<?php snippet('stream', ['current' => $page]) ?>
<?php endsnippet() ?>
