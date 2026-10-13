<?php
/**
 * The visitor numbers on About, from "Besucher" (plugins/analytics).
 */
$totals = blog_analytics_stats();
if ($totals === null) {
    return;
}
$plural = fn(int $n, string $word) => $n . ' ' . $word . ($n === 1 ? '' : 's');
?>
<div id="site-stats">
<h4>Statistics</h4>
<p><strong><?= $totals['visits'] ?></strong> visits and <strong><?= $totals['views'] ?></strong> page views across <strong><?= $totals['pages'] ?></strong> pages.</p>
<p>Most visited posts:</p>
<ul>
<?php foreach ($totals['top'] as [$post, $visits]): ?>
<li><a href="<?= $post->url() ?>"><?= $post->title()->esc() ?></a> - <?= $plural($visits, 'visit') ?></li>
<?php endforeach ?>
</ul>
</div>
