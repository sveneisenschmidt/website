<?php

use Kirby\Cms\Page;

/**
 * A post of any type, for templates/post.php: the section its links lead
 * to, the label above the title and the type line. plugins/blog registers
 * it for photo, trip, note and essay.
 */
return function (Page $page) {
    return [
        'section' => $page->parent()->slug(),
        'label' => $page->pick()->toBool() ? 'Featured photo' : $page->intendedTemplate()->name(),
        'type' => blog_type_line($page, false),
    ];
};
