<?php

namespace Website\Tests\Unit;

use Kirby\Data\Txt;
use PHPUnit\Framework\TestCase;

/**
 * make kirby-seed replaces www/content and keeps the prompt and the model
 * of the assistant (spec, "System-Prompt").
 */
final class SeedTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/website-seed-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        // The new site.txt as the import writes it.
        hugo_write($this->dir . '/new.txt', ['title' => 'Sven Eisenschmidt - Personal Blog', 'uuid' => 'abc'], HUGO_FIXED_TIME);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*'));
        rmdir($this->dir);
    }

    public function testPromptAndModelComeAlong(): void
    {
        $prompt = "PROMPT-CANARY-7f3a\n\n----\n\nline two: with a colon";
        file_put_contents($this->dir . '/old.txt', Txt::encode(['title' => 'Old', 'assistantPrompt' => $prompt, 'assistantModel' => 'claude-haiku-5-5']));

        $kept = seed_keep_site_fields($this->dir . '/old.txt', $this->dir . '/new.txt');

        $this->assertSame(['assistantPrompt', 'assistantModel'], $kept);
        $new = Txt::decode(file_get_contents($this->dir . '/new.txt'));
        $this->assertSame('Sven Eisenschmidt - Personal Blog', $new['title']);
        $this->assertSame($prompt, $new['assistantprompt']);
        $this->assertSame('claude-haiku-5-5', $new['assistantmodel']);
        $this->assertSame(HUGO_FIXED_TIME, filemtime($this->dir . '/new.txt'));
    }

    public function testWithoutAnOldPromptNothingChanges(): void
    {
        $before = file_get_contents($this->dir . '/new.txt');
        file_put_contents($this->dir . '/old.txt', Txt::encode(['title' => 'Old', 'assistantPrompt' => '']));

        $this->assertSame([], seed_keep_site_fields($this->dir . '/old.txt', $this->dir . '/new.txt'));
        $this->assertSame([], seed_keep_site_fields($this->dir . '/missing.txt', $this->dir . '/new.txt'));
        $this->assertSame($before, file_get_contents($this->dir . '/new.txt'));
    }
}
