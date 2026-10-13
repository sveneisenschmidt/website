<?php
/**
 * The results of a search, newest first, on the search page and in
 * search.json: by month, or the bar "Nothing found".
 *
 * @var Kirby\Cms\Pages $results
 */
?>
<?php if ($results->isEmpty()): ?>
<h2 class="archive-month stream-title"><span>Nothing found</span><span>0</span></h2>
<?php else: ?>
<?php snippet('months', ['posts' => $results]) ?>
<?php endif ?>
