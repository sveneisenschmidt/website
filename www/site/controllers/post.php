<?php

use Website\Models\PostPage;

/**
 * The variables of templates/post.php for all four post types; plugins/blog
 * registers the controller for each. `section` is the archive sub-page that
 * the label and the type line link to, `label` the text above the title,
 * `type` the type line after the date.
 */
return function (PostPage $page) {
    return [
        'section' => $page->parent()->slug(),
        'label' => $page->pick()->toBool() ? 'Featured photo' : $page->intendedTemplate()->name(),
        'type' => $page->typeLine(false),
    ];
};
