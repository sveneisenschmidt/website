<?php
/**
 * Everything in <head>, as Hugo's head.html, head/meta.html, head/css.html
 * and head/js.html: title, icons, feed, font, meta tags, JSON-LD, the CSS
 * inline, the scripts a page needs inline, Leaflet only with a map.
 *
 * @var Kirby\Cms\Page $page
 * @var Kirby\Cms\Site $site
 * @var string $kind
 */
$title = $page->isHomePage() ? $site->title()->value() : $page->title()->value() . ' · ' . $site->title()->value();
$description = blog_description($page);
$article = blog_is_post($page) || $page->intendedTemplate()->name() === 'about';
$cover = blog_share_cover($page);
$image = $cover ? $cover->thumb(['width' => 1200, 'height' => 630, 'crop' => true, 'format' => 'webp', 'quality' => 80])->url() : null;
$url = $page->url();
$layout = $page->intendedTemplate()->name();
$scripts = array_keys(array_filter([
    'scroll' => $kind === 'home' || $kind === 'section' || $layout === 'archive',
    'back' => $kind === 'page' || $layout === 'archive',
    'fit' => $kind === 'home',
    'copy' => $kind === 'page',
]));
$attr = fn (string $text) => htmlspecialchars($text, ENT_QUOTES, 'UTF-8', false);
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($title) ?></title>
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/site.webmanifest">
<link rel="alternate" type="application/rss+xml" title="<?= $site->title()->esc() ?>" href="/index.xml">
<link rel="preload" href="/fonts/hanken-grotesk-v12-latin.woff2" as="font" type="font/woff2" crossorigin>
<meta name="description" content="<?= $attr($description) ?>">
<meta name="author" content="<?= esc(BLOG_SITE['author']) ?>">
<meta name="keywords" content="<?= esc(BLOG_SITE['keywords']) ?>">
<meta name="google-site-verification" content="cNQT6i25KOyhszNP9zuFOD80fbWfvoemf65Z83uQ984">
<meta property="og:title" content="<?= esc($title) ?>">
<meta property="og:description" content="<?= $attr($description) ?>">
<meta property="og:type" content="<?= $article ? 'article' : 'website' ?>">
<meta property="og:url" content="<?= $url ?>">
<meta property="og:site_name" content="<?= $site->title()->esc() ?>">
<?php if ($article): ?>
<?php if ($page->date()->isNotEmpty()): ?>
<meta property="article:published_time" content="<?= $page->date()->toDate('c') ?>">
<?php endif ?>
<meta property="article:modified_time" content="<?= date('c', blog_modified($page)) ?>">
<meta property="article:author" content="<?= esc(BLOG_SITE['author']) ?>">
<?php endif ?>
<?php if ($image): ?>
<meta property="og:image" content="<?= $image ?>">
<?php endif ?>
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= esc($title) ?>">
<meta name="twitter:description" content="<?= $attr($description) ?>">
<?php if ($image): ?>
<meta name="twitter:image" content="<?= $image ?>">
<?php endif ?>
<link rel="canonical" href="<?= $url ?>">
<?php if ($page->isHomePage()): ?>
<script type="application/ld+json"><?= blog_json([
    '@context' => 'https://schema.org',
    '@type' => 'Person',
    'name' => BLOG_SITE['author'],
    'url' => $site->url() . '/',
    'jobTitle' => BLOG_SITE['jobTitle'],
    'worksFor' => ['@type' => 'Organization', 'name' => BLOG_SITE['employer'], 'url' => BLOG_SITE['employerUrl']],
    'sameAs' => [BLOG_SITE['githubUrl'], BLOG_SITE['linkedinUrl'], BLOG_SITE['stravaUrl']],
]) ?></script>
<?php elseif (blog_is_post($page)): ?>
<script type="application/ld+json"><?= blog_json(array_filter([
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => $page->title()->value(),
    'datePublished' => $page->date()->toDate('c'),
    'dateModified' => date('c', blog_modified($page)),
    'image' => $image,
    'url' => $url,
    'description' => $description,
    'author' => ['@type' => 'Person', 'name' => BLOG_SITE['author'], 'url' => $site->url() . '/'],
    'publisher' => ['@type' => 'Person', 'name' => BLOG_SITE['author'], 'url' => $site->url() . '/'],
])) ?></script>
<?php endif ?>
<style><?= blog_css() ?></style>
<?php foreach ($scripts as $script): ?>
<script><?= Kirby\Filesystem\F::read(kirby()->root('assets') . "/js/$script.js") ?></script>
<?php endforeach ?>
<?php if ($layout === 'search'): ?>
<script type="module"><?= Kirby\Filesystem\F::read(kirby()->root('assets') . '/js/search.js') ?></script>
<?php endif ?>
<?php if (blog_has_map($page)): ?>
<link rel="stylesheet" href="<?= blog_asset('leaflet/leaflet.css') ?>">
<script src="<?= blog_asset('leaflet/leaflet.js') ?>" defer></script>
<script src="<?= blog_asset('js/map.js') ?>" defer></script>
<?php endif ?>
