<?php

namespace Website\Tests\Integration;

/**
 * guard.php: every proposed text, before a card exists. The tests check
 * security and content model only, never style.
 */
final class GuardTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['a.jpg', 'b.jpg'] as $name) {
            $this->upload($this->jpeg(8, 8, $name), $name);
        }
    }

    /** A text for the field "text" of the photo with the images a.jpg and b.jpg; without $map, of a note. */
    private function markdown(string $text, array $allowed = [], bool $map = true): ?string
    {
        $page = $map ? $this->post : $this->kirby->page('notes/a-note') ?? $this->post('notes', 'note', 'a-note', 'A note');
        return blog_guard_markdown($text, $allowed, $page);
    }

    public function testPlainMarkdownPasses(): void
    {
        $this->assertNull($this->markdown("We drove **north**.\n\n(image: a.jpg)\n\n(map:)\n\n(Update: the ferry was late.)"));
        $this->assertNull($this->markdown('(image: a.jpg imgclass: inline)'));
    }

    public function testRawHtmlIsRefused(): void
    {
        foreach (['<script>alert(1)</script>', 'A <a href="https://x.test">link</a>', '<!-- note -->', '</p>', '<?php echo 1; ?>', '<https://x.test>'] as $text) {
            $this->assertStringContainsString('HTML', (string) $this->markdown($text), $text);
        }
        $this->assertNull($this->markdown('3 < 4 and 5 > 2'));
    }

    public function testOnlyThreeKirbyTagsAreAllowed(): void
    {
        foreach (['(email: me@x.test)', '(file: a.jpg)', '(video: https://youtube.com/x)', '(youtube: https://x)', '(date: year)', '(gist: x)', '(tel: 123)'] as $text) {
            $this->assertStringContainsString('not allowed', (string) $this->markdown($text), $text);
        }
        $this->assertStringContainsString('names no image', (string) $this->markdown('(image: c.jpg)'));
        // (image:) knows only imgclass: anything else is part of the file name.
        $this->assertStringContainsString('names no image', (string) $this->markdown('(image: a.jpg link: https://x.test)'));
        $this->assertStringContainsString('photo and trip', (string) $this->markdown('(map:)', [], false));
        $this->assertStringContainsString('page://', (string) $this->markdown('(link: https://x.test text: X)'));
        $this->assertStringContainsString('page://', (string) $this->markdown('(link: page:// text: X)'));
    }

    public function testAPageLinkNeedsAnExistingPage(): void
    {
        $uuid = $this->post->uuid()->toString();
        $this->assertNull($this->markdown("(link: $uuid text: the test photo)"));
        $this->assertNotNull($this->markdown('(link: page://doesnotexist1234 text: X)'));
        $this->assertStringContainsString('text:', (string) $this->markdown("(link: $uuid target: _blank)"));
    }

    public function testLinksGoOnlyToKnownUrls(): void
    {
        $allowed = ['https://known.test/a', 'mailto:me@x.test'];
        $this->assertNull($this->markdown('See [this](https://known.test/a), mail [me](mailto:me@x.test) or https://known.test/a.', $allowed));
        foreach (['[x](https://evil.test)', 'Go to https://evil.test/path now.', "[x][1]\n\n[1]: https://evil.test", '![x](https://evil.test/p.png)', '[x](javascript:alert(1))', '[x](/2024-01-01-old/)'] as $text) {
            $this->assertStringContainsString('not allowed', (string) $this->markdown($text, $allowed), $text);
        }
    }

    /**
     * The probes of the security review (2026-10-12): Markdown makes links
     * of nested brackets, of reference definitions in lists, quotes and
     * code, and of link texts, where regexes on the source see none.
     */
    public function testLinksHiddenFromRegexesAreRefused(): void
    {
        $uuid = $this->post->uuid()->toString();
        $probes = [
            '[a [b] c](javascript:alert(document.cookie))',
            '[a [b] c](//evil.example/)',
            '[a [b] c](https:\\\\evil.example/)',
            '![a [b] c](//evil.example/p.png)',
            "[x][1]\n\n- [1]: //evil.example/",
            "[x][1]\n\n> [1]: javascript:alert(1)",
            "[x][1]\n\n[1]: //evil.example/",
            "[x][1]\n\n   [1]: javascript:alert(1)",
            "[x][1]\n\n\t[1]: //evil.example/",
            '[a `]` b](//evil.example/)',
            '[a\\]](//evil.example/)',
            '[x](//evil.example/ "t")',
            '(see https://evil.example)',
            '<_x onmouseover=alert(1)>hi</_x>',
            "- [1]: javascript:alert(1)\n- [x][1]",
            "> [1]: //evil.example/\n>\n> see [x][1]",
            "(link: $uuid text: [a [b] c](//evil.example))",
        ];
        foreach ($probes as $text) {
            $this->assertNotNull($this->markdown($text, ['https://ok.example']), $text);
        }
        $this->assertNotNull(blog_guard_caption('[a [b] c](javascript:alert(1))', []));
        $this->assertNotNull(blog_guard_caption('x ![a [b] c](//evil.example/p.png)', []));
    }

    public function testImagesComeOnlyFromThisPost(): void
    {
        $this->assertStringContainsString('(image:', (string) $this->markdown('![x](https://ok.example/p.png)', ['https://ok.example/p.png']));
        $this->assertStringContainsString('names no image', (string) $this->markdown('(image: photos/test-photo/a.jpg)'));
    }

    public function testLinksTargetsAreFound(): void
    {
        $this->assertSame(
            ['https://a.test', 'https://b.test/x', 'https://c.test', 'https://d.test/y'],
            blog_guard_links("[a](https://a.test) ![b](https://b.test/x \"title\")\n\n[ref]: https://c.test\n\nBare https://d.test/y."),
        );
    }

    public function testCaptionsAreTextWithKnownLinks(): void
    {
        $this->assertNull(blog_guard_caption('Taken on [the roof](https://known.test).', ['https://known.test']));
        $this->assertNotNull(blog_guard_caption('Taken on [the roof](https://evil.test).', ['https://known.test']));
        $this->assertNotNull(blog_guard_caption('(image: a.jpg)', []));
        $this->assertNotNull(blog_guard_caption('![x](https://known.test)', ['https://known.test']));
        $this->assertNotNull(blog_guard_caption('<b>x</b>', []));
    }

    public function testPlainTextHasNoMarkup(): void
    {
        $this->assertNull(blog_guard_plain('Summer Vacation, Day 5: Brighton'));
        $this->assertNotNull(blog_guard_plain("Two\nlines"));
        $this->assertNotNull(blog_guard_plain('<b>bold</b>'));
        $this->assertNull(blog_guard_plain(str_repeat('a', 60), 60));
        $this->assertNotNull(blog_guard_plain(str_repeat('a', 61), 60));
    }
}
