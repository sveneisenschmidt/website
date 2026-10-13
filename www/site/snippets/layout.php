<?php
use Website\Blog\Site;

/**
 * The HTML document around the content of every page: head, site header,
 * main and footer. A template puts its content between
 * snippet('layout', slots: true) and endsnippet(), and the content arrives
 * as $slot. The class of the body is the name of the template.
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
<h1 class="masthead"><a href="/"><?= esc(Site::heading()) ?></a></h1>
<?php else: ?>
<p class="masthead"><a href="/"><?= esc(Site::heading()) ?></a></p>
<?php endif ?>
</header>
<main id="main"><?= $slot ?></main>
<footer class="site-footer">
<a href="/about/">About</a>
<a href="/index.xml">RSS</a>
</footer>
</body>
</html>
