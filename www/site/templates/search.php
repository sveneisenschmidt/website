<?php
/**
 * Search, as Hugo's search.html. With ?q= the results come from the
 * server; search.js asks search.json while typing.
 *
 * @var Kirby\Cms\Page $page
 */
$query = trim((string)get('q'));
?>
<?php snippet('layout', slots: true) ?>
<section class="archive">
<?php snippet('stream-filter', ['current' => 'search']) ?>
<form class="search-form<?= $query !== '' ? ' has-term' : '' ?>" role="search">
<input
class="search-input"
type="search"
name="q"
aria-label="Search"
autocomplete="off"
enterkeyhint="search"
autofocus
data-search-input
<?php if ($query !== ''): ?>value="<?= esc($query) ?>"
<?php endif ?>>
<span class="search-caret" aria-hidden="true"></span>
</form>
<div class="search-results" data-search-results><?php if ($query !== ''): ?>
<?php $results = blog_search($query) ?>
<?php if ($results->isEmpty()): ?>
<h2 class="archive-month stream-title"><span>Nothing found</span><span>0</span></h2>
<?php else: ?>
<?php snippet('months', ['posts' => $results]) ?>
<?php endif ?>
<?php endif ?></div>
</section>
<?php endsnippet() ?>
