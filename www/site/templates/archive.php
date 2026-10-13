<?php
/**
 * The archive and its four streams, as Hugo's archive.html. Photos are
 * the photo posts and the trips with a cover.
 *
 * @var Kirby\Cms\Page $page
 */
$stream = $page->stream()->or('all')->value();
$posts = blog_posts();
if ($stream === 'photos') {
    $posts = $posts->filter(fn ($post) => $post->parent()->id() === 'photos' || ($post->parent()->id() === 'trips' && $post->cover()->isNotEmpty()));
} elseif ($stream !== 'all') {
    $posts = $posts->filter(fn ($post) => $post->parent()->id() === $stream);
}
?>
<?php snippet('layout', slots: true) ?>
<section class="archive">
<?php snippet('stream-filter', ['current' => $stream]) ?>
<h1 class="archive-heading">Archive</h1>
<?php snippet('months', ['posts' => $posts, 'sheet' => $stream === 'photos']) ?>
</section>
<?php endsnippet() ?>
