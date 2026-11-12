<?php

namespace Website\Assistant;

use Dom\HTMLDocument;
use Kirby\Cms\Page;
use Kirby\Text\KirbyTag;

/**
 * The parts of a text that a card highlights as links, so the Panel user
 * sees every link before the click. A card is a change that Claude proposed;
 * the chat shows it with buttons to apply or discard it.
 */
final class Marks
{
    /**
     * A KirbyText tag, with the pattern of `Kirby\Text\KirbyTags::parse()`,
     * so the marks match the tags that Kirby renders.
     */
    private const TAG = '!(?=[^\]])(?=\([a-z0-9_-]+:)(\((?:[^()]+|(?1))*+\))!is';

    /**
     * Each link target as the text writes it, and each (link:) tag. The text
     * renders as the site renders it, in the context of $page. $caption
     * renders it inline, as (image:) renders a caption. Links inside a
     * figure come from an (image:) tag and are not marked.
     *
     * @return list<string>
     */
    public static function of(string $text, ?Page $page = null, bool $caption = false): array
    {
        if (trim($text) === '') {
            return [];
        }
        $options = $caption ? ['markdown' => ['inline' => true]] : [];
        $html = kirby()->kirbytext($text, $page === null ? $options : ['parent' => $page] + $options);
        $body = HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html, LIBXML_NOERROR, 'UTF-8')->body;
        $marks = [];
        foreach ($body->querySelectorAll('a[href]') as $link) {
            $href = (string) $link->getAttribute('href');
            if ($href !== '' && $link->closest('figure') === null && str_contains($text, $href)) {
                $marks[] = $href;
            }
        }
        // Text in parentheses such as "(Update: …)" matches `TAG` too, and
        // KirbyTag::parse() throws for a type that Kirby does not know.
        preg_match_all(self::TAG, $text, $m);
        foreach ($m[1] as $tag) {
            $type = strtolower(trim(substr($tag, 1, (int) strpos($tag, ':') - 1)));
            if ($type === 'link' && Content::page(trim((string) KirbyTag::parse($tag)->value)) !== null) {
                $marks[] = $tag;
            }
        }
        return array_values(array_unique($marks));
    }
}
