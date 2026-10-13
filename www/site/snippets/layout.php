<?php
/**
 * The frame of every page, as Hugo's baseof.html. A template puts its
 * content between snippet('layout', slots: true) and endsnippet(). The
 * kind is Hugo's .Kind: home, page, section or 404.
 *
 * @var Kirby\Cms\Page $page
 * @var Kirby\Template\Slot $slot
 */
$kind = blog_kind($page);
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
<?php snippet('head', ['kind' => $kind]) ?>
</head>
<body class="<?= $kind ?>">
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header">
<?php if ($page->isHomePage()): ?>
<h1 class="masthead"><a href="/"><?= esc(BLOG_SITE['heading']) ?></a></h1>
<?php else: ?>
<p class="masthead"><a href="/"><?= esc(BLOG_SITE['heading']) ?></a></p>
<?php endif ?>
</header>
<main id="main"><?= $slot ?></main>
<footer class="site-footer">
<a href="/about/">About</a>
<a href="/index.xml">RSS</a>
</footer>
</body>
</html>
