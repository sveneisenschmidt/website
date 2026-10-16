<?php

use Kirby\Cms\App;

/**
 * Registers the plugin by name. The plugin has no PHP part: index.js sends a
 * failed draft save once more. Without this file, Kirby registers the folder
 * again with every new App, and the second App in one PHP process throws a
 * DuplicateException.
 */

App::plugin('website/save-retry', []);
