<?php

declare(strict_types=1);

namespace MemoryDown\Memory;

/**
 * Builds the slug part of a Markdown memory filename from a human title.
 *
 * Diacritics are transliterated, not dropped: "Članek o šoli" becomes
 * "clanek-o-soli", not "lanek-o-oli". A filename is the one place where ASCII
 * is worth the compromise (it survives FTP uploads, zip archives, odd
 * shared-hosting filesystems and shell quoting), so the accented letters are
 * folded to their base letter rather than silently removed.
 *
 * This affects filenames only. The title stored in the frontmatter and the
 * body keep their č/š/ž untouched — nothing here rewrites content.
 *
 * The single-character fold lives in {@see Text}, which search uses too, so
 * the two cannot drift apart. What is left here is the multi-character
 * transliteration a filename may safely absorb but a search offset may not.
 *
 * The tables are static and explicit on purpose: `iconv('…', 'ASCII//TRANSLIT')`
 * depends on the system locale and can emit `?` or drop the character on
 * shared hosting, which is exactly the bug this class exists to avoid. Anything
 * not folded (Cyrillic, CJK, emoji) still falls back to the old behaviour: it
 * becomes a separator. No new dependency, no `intl`.
 */
final class Slug
{
    /** Longest slug kept in a filename; the random suffix is added after it. */
    public const MAX_LENGTH = 48;

    /** Used when a title has nothing a filename can be built from. */
    public const FALLBACK = 'memory';

    /**
     * Letters that need more than one ASCII character. They cannot live in
     * {@see Text::fold()}, whose contract is one character to one character.
     * Lowercase only: the title is folded (and lowercased) before strtr().
     *
     * @var array<string, string>
     */
    private const DIGRAPHS = [
        'æ' => 'ae',
        'þ' => 'th',
        'ß' => 'ss',
        // The Croatian digraphs, which Unicode keeps as single letters.
        'ǆ' => 'dz', 'ǉ' => 'lj', 'ǌ' => 'nj',
    ];

    /**
     * The slug for a title: lowercase ASCII words joined by single hyphens,
     * at most MAX_LENGTH characters, never empty.
     */
    public static function fromTitle(string $title): string
    {
        $folded = strtr(Text::fold(trim($title)), self::DIGRAPHS);

        // Whatever the table did not fold is a separator, as before.
        $slug = preg_replace('/[^a-z0-9]+/', '-', $folded) ?? '';
        $slug = trim($slug, '-');

        // Safe to cut by bytes: the regex above left nothing but ASCII.
        $slug = substr($slug, 0, self::MAX_LENGTH);
        $slug = rtrim($slug, '-');

        return '' !== $slug ? $slug : self::FALLBACK;
    }
}
