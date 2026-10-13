<?php
use Website\Blog\Site;

/**
 * The frame of every page. A template puts its content between
 * snippet('layout', slots: true) and endsnippet(). The body carries the
 * name of the template as its class.
 *
 * @var Kirby\Cms\Page $page
 * @var Kirby\Template\Slot $slot
 */
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
<?php snippet('head') ?>
</head>
<body class="<?= $page->intendedTemplate()->name() ?>">
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header">
<?php if ($page->isHomePage()): ?>
<h1 class="masthead"><a href="/"><?= esc(Site::HEADING) ?></a></h1>
<?php else: ?>
<p class="masthead"><a href="/"><?= esc(Site::HEADING) ?></a></p>
<?php endif ?>
</header>
<main id="main"><?= $slot ?></main>
<footer class="site-footer">
<a href="/about/">About</a>
<a href="/index.xml">RSS</a>
</footer>
</body>
</html>
