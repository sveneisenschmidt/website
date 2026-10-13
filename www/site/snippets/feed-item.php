<?php
/**
 * The description of a post in the feed: the cover, the summary and a
 * link to the rest. One line: `Website\Blog\Feeds::rss()` puts it into a
 * CDATA block, and feed readers show it as HTML.
 *
 * @var Website\Models\PostPage $post
 * @var array{url: string, width: int, height: int}|null $cover
 * @var string $summary
 * @var int $more words of the post after the summary
 * @var string $link
 */
?>
<?php if ($cover !== null): ?><img src="<?= $cover['url'] ?>" width="<?= $cover['width'] ?>" height="<?= $cover['height'] ?>" alt="<?= esc($post->title()->value() . ' cover image') ?>" /><?php endif ?><p><?= esc($summary) ?><?php if ($more > 0): ?> (<a href="<?= $link ?>" title="Continue reading <?= esc($post->title()->value()) ?>"><?= $more ?> more words</a>)<?php endif ?></p>
