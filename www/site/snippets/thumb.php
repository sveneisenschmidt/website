<?php
use Website\Blog\Images;

/**
 * The square cover of a post in a list: 48 px, 96 px for 2x screens.
 * Nothing without a cover.
 *
 * @var Website\Models\PostPage $post
 */
if ($cover = $post->coverImage()):
    $one = Images::square($cover, 48, 80);
    $two = Images::square($cover, 96, 80);
    ?>
<span class="thumb">
<img src="<?= $one->url() ?>" srcset="<?= $one->url() ?> 1x, <?= $two->url() ?> 2x" width="48" height="48" alt="" loading="lazy" decoding="async">
</span>
<?php endif ?>
