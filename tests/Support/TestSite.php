<?php

namespace Website\Tests\Support;

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;

/**
 * A Kirby instance on a temporary folder. The code (blueprints, plugins,
 * templates, snippets, config.php) comes from www/site. Content, accounts,
 * cache and media are in the temporary folder, so a test never changes the
 * local site. env.php and .salt are left out.
 */
final class TestSite
{
    public const SITE = __DIR__ . '/../../www/site';

    public static function create(): string
    {
        $root = sys_get_temp_dir() . '/website-test-' . bin2hex(random_bytes(6));
        Dir::make($root . '/config');
        // Every config file that all hosts share, such as config.php and
        // routes.php. env.php is host config and stays out. .salt and
        // .license do not match *.php.
        foreach (glob(self::SITE . '/config/*.php') as $file) {
            if (basename($file) !== 'env.php') {
                copy($file, $root . '/config/' . basename($file));
            }
        }
        Dir::make($root . '/content');
        file_put_contents($root . '/content/site.txt', "Title: Personal Blog\n");
        return $root;
    }

    /**
     * $props: further App props, such as hooks.
     *
     * @param array<mixed> $options
     * @param array<mixed> $props
     */
    public static function app(string $root, array $options = [], array $props = []): App
    {
        return new App([
            'roots' => [
                'index' => $root,
                'site' => realpath(self::SITE),
                'assets' => realpath(self::SITE . '/../assets'),
                'config' => $root . '/config',
                'content' => $root . '/content',
                'accounts' => $root . '/accounts',
                'cache' => $root . '/cache',
                'media' => $root . '/media',
                'sessions' => $root . '/sessions',
                'logs' => $root . '/logs',
                'storage' => $root . '/storage',
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
