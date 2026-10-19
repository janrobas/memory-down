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
 * The table is static and explicit on purpose: `iconv('…', 'ASCII//TRANSLIT')`
 * depends on the system locale and can emit `?` or drop the character on
 * shared hosting, which is exactly the bug this class exists to avoid. Anything
 * not in the table (Cyrillic, CJK, emoji) still falls back to the old
 * behaviour: it becomes a separator. No new dependency, no `intl`.
 */
final class Slug
{
    /** Longest slug kept in a filename; the random suffix is added after it. */
    public const MAX_LENGTH = 48;

    /** Used when a title has nothing a filename can be built from. */
    public const FALLBACK = 'memory';

    /**
     * Lowercase only: the title is lowercased with mb_strtolower() first, so
     * "Č" is already "č" by the time strtr() looks at it.
     *
     * @var array<string, string>
     */
    private const TRANSLITERATION = [
        // Latin-1 Supplement.
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'æ' => 'ae',
        'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ð' => 'd',
        'ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ø' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'þ' => 'th',
        'ß' => 'ss',

        // Latin Extended-A: the Czech/Slovak/Polish/Croatian/Baltic/Turkish
        // letters. Slovenian lives here too (č, š, ž).
        'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
        'ć' => 'c', 'ĉ' => 'c', 'ċ' => 'c', 'č' => 'c',
        'ď' => 'd', 'đ' => 'd',
        'ē' => 'e', 'ĕ' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
        'ĝ' => 'g', 'ğ' => 'g', 'ġ' => 'g', 'ģ' => 'g',
        'ĥ' => 'h', 'ħ' => 'h',
        'ĩ' => 'i', 'ī' => 'i', 'ĭ' => 'i', 'į' => 'i', 'ı' => 'i',
        'ĵ' => 'j',
        'ķ' => 'k',
        'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l', 'ł' => 'l',
        'ń' => 'n', 'ņ' => 'n', 'ň' => 'n', 'ŉ' => 'n',
        'ō' => 'o', 'ŏ' => 'o', 'ő' => 'o',
        'ŕ' => 'r', 'ŗ' => 'r', 'ř' => 'r',
        'ś' => 's', 'ŝ' => 's', 'ş' => 's', 'š' => 's', 'ſ' => 's',
        'ţ' => 't', 'ť' => 't', 'ŧ' => 't',
        'ũ' => 'u', 'ū' => 'u', 'ŭ' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
        'ŵ' => 'w',
        'ŷ' => 'y',
        'ź' => 'z', 'ż' => 'z', 'ž' => 'z',

        // Latin Extended-B odds and ends that show up in names.
        'ș' => 's', 'ț' => 't', 'ǎ' => 'a', 'ǐ' => 'i', 'ǒ' => 'o', 'ǔ' => 'u',
        'ǧ' => 'g', 'ǩ' => 'k', 'ǫ' => 'o', 'ǰ' => 'j', 'ǵ' => 'g',
        // The Croatian digraphs, which Unicode keeps as single letters.
        'ǆ' => 'dz', 'ǉ' => 'lj', 'ǌ' => 'nj',
    ];

    /**
     * The slug for a title: lowercase ASCII words joined by single hyphens,
     * at most MAX_LENGTH characters, never empty.
     */
    public static function fromTitle(string $title): string
    {
        $lower = mb_strtolower(trim($title), 'UTF-8');
        $folded = strtr($lower, self::TRANSLITERATION);

        // Whatever the table did not fold is a separator, as before.
        $slug = preg_replace('/[^a-z0-9]+/', '-', $folded) ?? '';
        $slug = trim($slug, '-');

        // Safe to cut by bytes: the regex above left nothing but ASCII.
        $slug = substr($slug, 0, self::MAX_LENGTH);
        $slug = rtrim($slug, '-');

        return '' !== $slug ? $slug : self::FALLBACK;
    }
}
