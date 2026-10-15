<?php

namespace Website\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Website\Analytics\Kind;

final class KindTest extends TestCase
{
    private const SAFARI = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15';

    public function testABrowserSendsBothHeaders(): void
    {
        $this->assertSame(Kind::BROWSER, Kind::of(self::SAFARI, true, true));
    }

    public function testABrowserNameWithoutAHeaderIsSuspect(): void
    {
        $this->assertSame(Kind::SUSPECT, Kind::of(self::SAFARI, false, true));
        $this->assertSame(Kind::SUSPECT, Kind::of(self::SAFARI, true, false));
    }

    public function testAProgramIsABotWithOrWithoutTheHeaders(): void
    {
        $this->assertSame(Kind::BOT, Kind::of('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', true, true));
        $this->assertSame(Kind::BOT, Kind::of('curl/8.7.1', false, false));
        $this->assertSame(Kind::BOT, Kind::of('', false, false));
    }

    public function testBotNames(): void
    {
        $this->assertSame('Googlebot', Kind::botName('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'));
        $this->assertSame('GPTBot', Kind::botName('Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)'));
        $this->assertSame('HeadlessChrome', Kind::botName('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/131.0.0.0 Safari/537.36'));
        $this->assertSame('Feedly', Kind::botName('Feedly/1.0 (+http://www.feedly.com/fetcher.html; 5 subscribers)'));
        $this->assertSame('website-warm', Kind::botName('website-warm (bot)'));
        $this->assertSame('Java', Kind::botName('Java/17.0.2'));
        $this->assertSame('ohne Namen', Kind::botName(''));
    }
}
