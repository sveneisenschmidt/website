<?php
use Website\Blog\Site;

/**
 * A post of any type: label, title, date and type line, the text, a mail
 * link, the like button, and the timeline with the newer and the older
 * post. Variables from controllers/post.php. The footer is a div, because
 * a form cannot stand in a p.
 *
 * @var Website\Models\PostPage $page
 * @var string $section
 * @var string $label
 * @var string $type
 * @var int $likes
 * @var bool $liked
 */
?>
<?php snippet('layout', slots: true) ?>
<article class="article">
<div class="article-head">
<p class="stream-title stream-opening"><a href="/archive/<?= $section ?>/"><?= $label ?></a>
<a class="far" href="/" data-back>Back</a>
</p>
<h1><?= $page->title()->esc() ?></h1>
<p class="metadata">
<a href="/archive/"><time datetime="<?= $page->date()->toDate('Y-m-d') ?>"><?= $page->dateText() ?></time></a>
<?php if ($type !== ''): ?>&middot; <a href="/archive/<?= $section ?>/"><?= esc($type) ?></a><?php endif ?>
</p>
</div>
<div class="body">
<?= $page->bodyHtml() ?>
</div>
<div class="post-footer">
<?php if (Site::email() !== ''): ?><a href="mailto:<?= esc(Site::email()) ?>?subject=<?= rawurlencode('Re: ' . $page->title()->value()) ?>">Leave me a message</a>
<?php endif ?>
<form class="like" id="like" method="post" action="<?= $page->url() ?>like"><?php if ($liked): ?><strong>Liked</strong><?php else: ?><button type="submit">Like</button><?php endif ?><?= $likes > 0 ? " &middot; $likes" : '' ?></form>
</div>
</article>
<?php snippet('stream', ['current' => $page]) ?>
<?php endsnippet() ?>
