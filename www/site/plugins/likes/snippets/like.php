<?php
use Website\Likes\Likes;

/**
 * The like button at the end of a post: "Like · N", or "Liked · N" right
 * after a like of this IP address (Likes::button()). Without likes the
 * count is left out.
 *
 * @var Website\Models\PostPage $page
 */
['likes' => $likes, 'liked' => $liked] = Likes::button($page);
?>
<form class="like" id="like" method="post" action="<?= $page->url() ?>like"><?php if ($liked): ?><strong>Liked</strong><?php else: ?><button type="submit">Like</button><?php endif ?><?= $likes > 0 ? " &middot; $likes" : '' ?></form>
