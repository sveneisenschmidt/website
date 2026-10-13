<?php

namespace Website\Tests\Unit;

use Kirby\Data\Txt;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../bin/prompt-guard.php';

/**
 * The git hook of make hooks: no commit and no push with 8 words in
 * a row from the prompt. The prompt here is a marker.
 */
final class PromptGuardTest extends TestCase
{
    private const PROMPT = "PROMPT-CANARY-7f3a alpha bravo charlie delta.\n\n- echo foxtrot golf hotel india juliett";

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/website-guard-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/www/content', 0777, true);
        file_put_contents($this->dir . '/www/content/site.txt', Txt::encode(['title' => 'Site', 'assistantPrompt' => self::PROMPT]));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** Runs the hook in a git repository below $this->dir; returns its exit code. */
    private function hook(): int
    {
        exec('cd ' . escapeshellarg($this->dir) . ' && ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(realpath(__DIR__ . '/../../bin/prompt-guard.php')) . ' pre-commit 2>/dev/null', $out, $code);
        return $code;
    }

    public function testThePromptIsReadFromSiteTxt(): void
    {
        $this->assertSame(self::PROMPT, prompt_guard_prompt($this->dir . '/www/content/site.txt'));
        $this->assertSame('', prompt_guard_prompt($this->dir . '/missing.txt'));
    }

    public function testEightWordsInARowAreAHit(): void
    {
        $diff = "+++ b/notes.md\n+we wrote charlie delta echo foxtrot\n+Golf, hotel, *india*: juliett and more\n+++ b/other.md\n+alpha bravo charlie delta echo foxtrot golf\n";
        $this->assertSame(['notes.md'], prompt_guard_hits(self::PROMPT, $diff));
        $this->assertSame([], prompt_guard_hits(self::PROMPT, "+++ b/x.md\n-alpha bravo charlie delta echo foxtrot golf hotel\n"), 'Removed lines do not count.');
        $this->assertSame([], prompt_guard_hits('too short', "+++ b/x.md\n+too short\n"));
    }

    public function testTheHookStopsAStagedLeak(): void
    {
        exec('cd ' . escapeshellarg($this->dir) . ' && git init -q && printf "www/content/\n" > .gitignore && printf "Hello world.\n" > post.md && git add .gitignore post.md');
        $this->assertSame(0, $this->hook());

        file_put_contents($this->dir . '/post.md', "Charlie delta echo foxtrot golf hotel india juliett.\n");
        exec('cd ' . escapeshellarg($this->dir) . ' && git add post.md');

        $this->assertSame(1, $this->hook());
    }
}
