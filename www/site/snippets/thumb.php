<?php
use Website\Blog\Images;

/**
 * The square cover of a post in a list, with a second size for 2x
 * screens (Images::tile()). Nothing without a cover.
 *
 * @var Website\Models\PostPage $post
 */
if ($cover = $post->coverImage()):
    $square = Images::tile($cover, 'list');
    ?>
<span class="thumb">
<img src="<?= $square['src'] ?>" srcset="<?= $square['srcset'] ?>" width="<?= $square['edge'] ?>" height="<?= $square['edge'] ?>" alt="" loading="lazy" decoding="async">
</span>
<?php endif ?>
