<?php

namespace Website\Tests\Integration;

use FilesystemIterator;
use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Website\Tests\Support\HugoFixture;
use Website\Tests\Support\TestSite;

final class ImportTest extends TestCase
{
    private string $root;
    private string $hugo;
    private array $counts;
    private App $kirby;

    protected function setUp(): void
    {
        $this->root = TestSite::create();
        Dir::remove($this->root . '/content');
        $this->hugo = HugoFixture::create($this->root);
        $this->counts = hugo_import($this->hugo, $this->root . '/content', 'blog_read_exif');
        $this->kirby = TestSite::app($this->root);
    }

    protected function tearDown(): void
    {
        TestSite::remove($this->root);
    }

    public function testCountsPostsDraftsAndFiles(): void
    {
        $this->assertSame(['photo' => 1, 'trip' => 1, 'note' => 2, 'essay' => 1, 'drafts' => 1, 'files' => 4], $this->counts);
    }

    public function testPhotoPost(): void
    {
        $page = $this->kirby->page('photos/aika');
        $this->assertSame('photo', $page->intendedTemplate()->name());
        $this->assertTrue($page->isListed());
        $this->assertSame(20260311, $page->num());
        $this->assertSame('Aika', $page->title()->value());
        $this->assertSame('2026-03-11 20:40:00', $page->date()->value());
        $this->assertSame('It was dark, all images were blurry.', $page->intro()->value());
        $this->assertSame('(image: IMG_2334.JPG)', $page->text()->value());
        $this->assertSame('IMG_2334.JPG', $page->cover()->toFile()->filename());
        $this->assertTrue($page->pick()->toBool());

        $file = $page->file('IMG_2334.JPG');
        $this->assertSame('image', $file->template());
        $this->assertSame('Aika', $file->alt()->value());
        $this->assertSame('Taken on [the roof](https://example.com)', $file->caption()->value());
        $this->assertSame('Nadine', $file->photographer()->value());
    }

    public function testTripPostWithMapAndLink(): void
    {
        $page = $this->kirby->page('trips/2026-summer-vacation-5');
        $this->assertSame('summer-2026', $page->trip()->value());
        $this->assertSame('route.json', $page->route()->toFile()->filename());
        $this->assertSame('route', $page->file('route.json')->template());
        $this->assertTrue($page->mapzoom()->toBool());

        $points = $page->mappoints()->toStructure();
        $this->assertSame(2, $points->count());
        $this->assertSame("Norman's Bay, East Sussex", $points->first()->label()->value());
        $this->assertSame('-0.0970', $points->last()->lon()->value());

        $uuid = hugo_uid('page:aika');
        $this->assertStringContainsString("(link: page://$uuid text: Aika)", $page->intro()->value());
        $this->assertSame('photos/aika', $this->kirby->page("page://$uuid")->id());
        $this->assertSame("(image: A6404180.jpeg)\n\n(map:)", $page->text()->value());
    }

    public function testNotesWithAndWithoutIntroAndDraft(): void
    {
        $gemma = $this->kirby->page('notes/gemma');
        $this->assertSame('', $gemma->intro()->value());
        $this->assertSame('A note without intro.', $gemma->text()->value());

        $draft = $this->kirby->page('notes')->drafts()->find('draft-note');
        $this->assertNotNull($draft);
        $this->assertTrue($draft->isDraft());
    }

    public function testEssayWithEmailLink(): void
    {
        $this->assertSame(
            'Write me an [Email](mailto:sven.eisenschmidt@gmail.com?subject=Hypo%20App).',
            $this->kirby->page('essays/ambiguity')->intro()->value()
        );
    }

    public function testAboutArchiveSearchSectionsAndSite(): void
    {
        $this->assertSame('Sven Eisenschmidt - Personal Blog', $this->kirby->site()->title()->value());
        $this->assertStringStartsWith('> Personal website of Sven Eisenschmidt', $this->kirby->site()->llms()->value());
        $this->assertSame('404 Page not found', $this->kirby->page('error')->title()->value());

        $about = $this->kirby->page('about');
        $this->assertSame('Personal blog', $about->label()->value());
        $this->assertSame('2026-09-12', $about->lastmod()->value());
        // The block with Pop's numbers is gone: snippets/stats.php renders them.
        $this->assertSame("(image: sven.jpg imgclass: inline)\n\n<ul class=\"built\">\n<li>Reader</li>\n<li>Hypo</li>\n</ul>", $about->text()->value());
        $this->assertSame('A picture of Sven', $about->file('sven.jpg')->alt()->value());

        $this->assertSame('all', $this->kirby->page('archive')->stream()->value());
        $this->assertSame('essays', $this->kirby->page('archive/essays')->stream()->value());
        $this->assertSame('Search', $this->kirby->page('search')->title()->value());
        $this->assertSame('Photos', $this->kirby->page('photos')->title()->value());
    }

    public function testKeepsFileTimesAndIsDeterministic(): void
    {
        $post = $this->root . '/content/photos/20260311_aika';
        // Files take the time of their post: the same on every clone, as Hugo's sitemap.
        $time = (new \DateTimeImmutable('2026-03-11 20:40:00', new \DateTimeZone('Europe/Berlin')))->getTimestamp();
        $this->assertSame($time, filemtime("$post/photo.txt"));
        $this->assertSame($time, filemtime("$post/IMG_2334.JPG"));
        $this->assertSame($time, filemtime("$post/IMG_2334.JPG.txt"));
        $about = (new \DateTimeImmutable('2026-09-12 00:00:00', new \DateTimeZone('Europe/Berlin')))->getTimestamp();
        $this->assertSame($about, filemtime($this->root . '/content/about/about.txt'));

        $again = $this->root . '/content-again';
        hugo_import($this->hugo, $again, 'blog_read_exif');
        $this->assertSame(self::tree($this->root . '/content'), self::tree($again));
    }

    public function testStopsOnUnknownShortcodeAndNamesTheFile(): void
    {
        file_put_contents($this->hugo . '/notes/gemma.md', "+++\ndate = '2026-06-13T09:00:00'\ntitle = \"Gemma\"\ntype = \"note\"\n+++\n\n{{< youtube abc >}}\n");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('!notes/gemma\.md.*youtube!');
        hugo_import($this->hugo, $this->root . '/content-broken', 'blog_read_exif');
    }

    public function testStopsOnAFolderInsideABundle(): void
    {
        mkdir($this->hugo . '/photos/aika/content');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected folder in bundle');
        hugo_import($this->hugo, $this->root . '/content-broken', 'blog_read_exif');
    }

    public function testStopsOnAnUnknownContentFolder(): void
    {
        mkdir($this->hugo . '/posts/brand-new', 0755, true);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('!Unknown entry: .*/posts!');
        hugo_import($this->hugo, $this->root . '/content-broken', 'blog_read_exif');
    }

    public function testStopsOnAnUnderscoreEntryOtherThanIndex(): void
    {
        file_put_contents($this->hugo . '/notes/_draft.md', "+++\ntitle = \"X\"\n+++\n");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('_draft.md');
        hugo_import($this->hugo, $this->root . '/content-broken', 'blog_read_exif');
    }

    public function testStopsOnMissingTitleAndNamesTheFile(): void
    {
        file_put_contents($this->hugo . '/notes/gemma.md', "+++\ndate = '2026-06-13T09:00:00'\ntype = \"note\"\n+++\n\nText\n");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('!notes/gemma\.md: Missing front matter: title!');
        hugo_import($this->hugo, $this->root . '/content-broken', 'blog_read_exif');
    }

    /** Path => checksum (or "dir") and file time, for every file and folder. */
    private static function tree(string $dir): array
    {
        $out = [];
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($items as $item) {
            $path = substr($item->getPathname(), strlen($dir));
            $out[$path] = ($item->isDir() ? 'dir' : hash_file('sha256', $item->getPathname())) . ' ' . $item->getMTime();
        }
        ksort($out);
        return $out;
    }
}
