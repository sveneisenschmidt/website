<?php
/**
 * The search page: the filter row, the search field and the results. With
 * ?q= in the URL, the server renders the results. While the visitor types,
 * search.js loads them from search.json. Variables from controllers/search.php.
 *
 * @var string $query
 * @var Kirby\Cms\Pages $results
 */
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
<?php snippet('search-results', ['results' => $results]) ?>
<?php endif ?></div>
</section>
<?php endsnippet() ?>
