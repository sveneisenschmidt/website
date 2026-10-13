<?php
/**
 * The results of a search, newest first, for the search page and
 * search.json: the posts by month, or the heading "Nothing found".
 *
 * @var Kirby\Cms\Pages $results
 */
?>
<?php if ($results->isEmpty()): ?>
<h2 class="archive-month stream-title"><span>Nothing found</span><span>0</span></h2>
<?php else: ?>
<?php snippet('months', ['posts' => $results]) ?>
<?php endif ?>
