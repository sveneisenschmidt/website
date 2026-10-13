<?php

namespace Website\Tests\Unit;

use Kirby\Filesystem\Dir;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * make kirby-seed writes data/redirects.yaml into the content as the
 * redirects of the site (bin/lib/hugo.php).
 */
final class RedirectImportTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/website-redirects-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        Dir::remove($this->dir);
    }

    public function testWritesTheRedirectsWithTheirFlags(): void
    {
        file_put_contents($this->dir . '/redirects.yaml', <<<'YAML'
- from: /projects/
  to: /about/#things-i-built
  note: Nach /about/ gezogen

- from: /posts/daily-photo-aika/
  to: /2026-03-11-aika/
  pop: keep
  rss: keep
YAML);

        $this->assertSame(2, hugo_import_redirects($this->dir . '/redirects.yaml', $this->dir));

        $this->assertSame([
            ['from' => '/projects/', 'to' => '/about/#things-i-built', 'note' => 'Nach /about/ gezogen'],
            ['from' => '/posts/daily-photo-aika/', 'to' => '/2026-03-11-aika/', 'pop' => 'keep', 'rss' => 'keep'],
        ], json_decode(file_get_contents($this->dir . '/.redirects.json'), true));
        $this->assertSame(HUGO_FIXED_TIME, filemtime($this->dir . '/.redirects.json'));
    }

    public function testStopsOnAnUnknownKey(): void
    {
        file_put_contents($this->dir . '/redirects.yaml', "- from: /a/\n  to: /b/\n  status: 302\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unknown redirect');
        hugo_import_redirects($this->dir . '/redirects.yaml', $this->dir);
    }
}
