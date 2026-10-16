<?php

use Kirby\Cms\App;

/**
 * Registers the plugin, so the Panel loads index.js: a failed draft save
 * goes to the server once more (see index.js). The plugin has no PHP part.
 * Without this file, Kirby registers the folder again with every new App,
 * and the second App in one PHP process throws a DuplicateException.
 */

App::plugin('website/save-retry', []);
