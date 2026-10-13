<?php
/**
 * The row of links above archive and search: one link for each archive
 * page, Search, and Back.
 *
 * @var string $current The link to mark as current: all, photos, trips, notes, essays or search
 */
$items = ['all' => ['', 'All'], 'photos' => ['photos/', 'Photos'], 'trips' => ['trips/', 'Trips'], 'notes' => ['notes/', 'Notes'], 'essays' => ['essays/', 'Essays']];
?>
<nav class="stream-filter" aria-label="Archive and search">
<?php foreach ($items as $slug => [$path, $label]): ?>
<a href="/archive/<?= $path ?>"<?= $slug === $current ? ' aria-current="true"' : '' ?>><?= $label ?></a>
<?php endforeach ?>
<span class="stream-search">
<span class="stream-separator" aria-hidden="true">|</span>
<a href="/search/"<?= $current === 'search' ? ' aria-current="true"' : '' ?>>Search</a>
</span>
<span class="far"><a href="/" data-back>Back</a></span>
</nav>
