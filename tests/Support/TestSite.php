<?php

namespace Website\Tests\Support;

use Kirby\Cms\App;
use Kirby\Cms\Blueprint;
use Kirby\Filesystem\Dir;

/**
 * A Kirby instance on a temporary folder. Code (blueprints, plugins,
 * templates, snippets, config.php) comes from www/site. State (content,
 * accounts, cache, media) lives in the temporary folder, so a test never
 * touches the local site. Host config (env.php, .salt) is left out.
 */
final class TestSite
{
    public const SITE = __DIR__ . '/../../www/site';

    public static function create(): string
    {
        $root = sys_get_temp_dir() . '/website-test-' . bin2hex(random_bytes(6));
        Dir::make($root . '/config');
        // Every shared config file (config.php, later routes.php). env.php is
        // host config and stays out; .salt and .license are not *.php.
        foreach (glob(self::SITE . '/config/*.php') as $file) {
            if (basename($file) !== 'env.php') {
                copy($file, $root . '/config/' . basename($file));
            }
        }
        Dir::make($root . '/content');
        file_put_contents($root . '/content/site.txt', "Title: Sven Eisenschmidt - Personal Blog\n");
        return $root;
    }

    /** $props: further App props, such as roles. */
    public static function app(string $root, array $options = [], array $props = []): App
    {
        // Kirby caches blueprints per process. A blueprint can depend on the
        // user (Website › Assistent), so every test site starts without them.
        Blueprint::$loaded = [];
        return new App([
            'roots' => [
                'index' => $root,
                'site' => realpath(self::SITE),
                'config' => $root . '/config',
                'content' => $root . '/content',
                'accounts' => $root . '/accounts',
                'cache' => $root . '/cache',
                'media' => $root . '/media',
                'sessions' => $root . '/sessions',
                'logs' => $root . '/logs',
            ],
            'urls' => ['index' => 'http://localhost'],
            'options' => $options,
            ...$props,
        ]);
    }

    public static function remove(string $root): void
    {
        Dir::remove($root);
    }
}
