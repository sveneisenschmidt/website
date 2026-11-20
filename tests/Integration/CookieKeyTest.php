<?php

namespace Website\Tests\Integration;

use Kirby\Http\Cookie;
use Website\Tests\Support\TestSite;

/**
 * The key that signs Kirby's cookies (option cookie.key in config.php). It
 * comes from the salt of the host.
 */
final class CookieKeyTest extends IntegrationTestCase
{
    public function testAHostWithASaltSignsItsCookiesWithAKeyOfItsOwn(): void
    {
        // Kirby keeps a loaded config.php per path, so each salt gets a test
        // site of its own.
        $keys = [];
        foreach (['a', 'b'] as $letter) {
            $root = TestSite::create();
            try {
                file_put_contents("$root/config/.salt", str_repeat($letter, 48) . "\n");
                $keys[] = TestSite::app($root)->option('cookie.key');
            } finally {
                TestSite::remove($root);
            }
        }
        $this->kirby = TestSite::app($this->root);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $keys[0]);
        $this->assertNotSame($keys[0], $keys[1], 'another salt gives another key');
        $this->assertNotSame(Cookie::$key, $keys[0]);
        $this->assertStringNotContainsString(str_repeat('a', 48), (string) $keys[0], 'the key does not reveal the salt');
    }

    public function testWithoutASaltKirbyKeepsItsDefault(): void
    {
        $this->assertNull($this->kirby->option('cookie.key'));
    }
}
