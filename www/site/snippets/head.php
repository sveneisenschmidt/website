<?php
/**
 * Everything in <head>: title, icons, feed, font, meta tags, JSON-LD, the
 * CSS inline, the scripts a page needs inline, Leaflet only with a map
 * (plugins/blog/layout.php).
 *
 * @var Kirby\Cms\Page $page
 * @var Kirby\Cms\Site $site
 */
$meta = blog_meta($page);
$jsonld = blog_jsonld($page, $meta);
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($meta['title']) ?></title>
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/site.webmanifest">
<link rel="alternate" type="application/rss+xml" title="<?= $site->title()->esc() ?>" href="/index.xml">
<link rel="preload" href="/fonts/hanken-grotesk-v12-latin.woff2" as="font" type="font/woff2" crossorigin>
<meta name="description" content="<?= esc($meta['description']) ?>">
<meta name="author" content="<?= esc(BLOG_SITE['author']) ?>">
<meta name="keywords" content="<?= esc(BLOG_SITE['keywords']) ?>">
<meta name="google-site-verification" content="cNQT6i25KOyhszNP9zuFOD80fbWfvoemf65Z83uQ984">
<meta property="og:title" content="<?= esc($meta['title']) ?>">
<meta property="og:description" content="<?= esc($meta['description']) ?>">
<meta property="og:type" content="<?= $meta['article'] ? 'article' : 'website' ?>">
<meta property="og:url" content="<?= $meta['url'] ?>">
<meta property="og:site_name" content="<?= $site->title()->esc() ?>">
<?php if ($meta['article']): ?>
<?php if ($page->date()->isNotEmpty()): ?>
<meta property="article:published_time" content="<?= $page->date()->toDate('c') ?>">
<?php endif ?>
<meta property="article:modified_time" content="<?= date('c', blog_modified($page)) ?>">
<meta property="article:author" content="<?= esc(BLOG_SITE['author']) ?>">
<?php endif ?>
<?php if ($meta['image']): ?>
<meta property="og:image" content="<?= $meta['image'] ?>">
<?php endif ?>
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= esc($meta['title']) ?>">
<meta name="twitter:description" content="<?= esc($meta['description']) ?>">
<?php if ($meta['image']): ?>
<meta name="twitter:image" content="<?= $meta['image'] ?>">
<?php endif ?>
<link rel="canonical" href="<?= $meta['url'] ?>">
<?php if ($jsonld !== null): ?>
<script type="application/ld+json"><?= blog_json($jsonld) ?></script>
<?php endif ?>
<style><?= blog_css() ?></style>
<?php foreach (blog_scripts($page) as $script): ?>
<script<?= $script === 'search' ? ' type="module"' : '' ?>><?= blog_js($script) ?></script>
<?php endforeach ?>
<?php if (blog_has_map($page)): ?>
<link rel="stylesheet" href="<?= blog_asset('leaflet/leaflet.css') ?>">
<script src="<?= blog_asset('leaflet/leaflet.js') ?>" defer></script>
<script src="<?= blog_asset('js/map.js') ?>" defer></script>
<?php endif ?>
