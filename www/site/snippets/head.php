<?php
use Website\Blog\Layout;
use Website\Blog\Site;
use Website\Models\PostPage;

/**
 * The content of <head>: title, icons, feed link, font preload, meta tags,
 * JSON-LD, the inline CSS and scripts, and on a page with a map Leaflet
 * (the map library) and map.js. The values come from Website\Blog\Layout.
 *
 * @var Website\Models\SitePage $page
 * @var Kirby\Cms\Site $site
 */
$meta = Layout::meta($page);
$jsonld = Layout::jsonLd($page, $meta);
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
<?php if (Site::author() !== ''): ?>
<meta name="author" content="<?= esc(Site::author()) ?>">
<?php endif ?>
<?php if (Site::keywords() !== ''): ?>
<meta name="keywords" content="<?= esc(Site::keywords()) ?>">
<?php endif ?>
<meta name="google-site-verification" content="cNQT6i25KOyhszNP9zuFOD80fbWfvoemf65Z83uQ984">
<meta property="og:title" content="<?= esc($meta['title']) ?>">
<meta property="og:description" content="<?= esc($meta['description']) ?>">
<meta property="og:type" content="<?= $meta['article'] ? 'article' : 'website' ?>">
<meta property="og:url" content="<?= $meta['url'] ?>">
<meta property="og:site_name" content="<?= $site->title()->esc() ?>">
<?php if ($meta['article']): ?>
<?php if ($page instanceof PostPage && $page->date()->isNotEmpty()): ?>
<meta property="article:published_time" content="<?= $page->date()->toDate('c') ?>">
<?php endif ?>
<meta property="article:modified_time" content="<?= date('c', $page->lastModified()) ?>">
<?php if (Site::author() !== ''): ?>
<meta property="article:author" content="<?= esc(Site::author()) ?>">
<?php endif ?>
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
<script type="application/ld+json"><?= Layout::json($jsonld) ?></script>
<?php endif ?>
<style><?= Layout::css() ?></style>
<?php foreach (Layout::scripts($page) as $script): ?>
<script<?= $script === 'search' ? ' type="module"' : '' ?>><?= Layout::js($script) ?></script>
<?php endforeach ?>
<?php if ($page->hasMap()): ?>
<link rel="stylesheet" href="<?= Layout::asset('leaflet/leaflet.css') ?>">
<script src="<?= Layout::asset('leaflet/leaflet.js') ?>" defer></script>
<script src="<?= Layout::asset('js/map.js') ?>" defer></script>
<?php endif ?>
