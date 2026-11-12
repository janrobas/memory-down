<?php

declare(strict_types=1);

namespace MemoryDown\Memory;

/**
 * Character folding shared by search and filenames.
 *
 * Search needs "pošta" and "posta" to be the same word: Slovenian diacritics
 * are routinely typed without in a hurry, and the SQLite FTS5 index already
 * behaves that way (its `unicode61` tokenizer drops diacritics), so the direct
 * Markdown scan must agree or the two engines would answer differently.
 *
 * Every mapping here is one character to one character on purpose: the folded
 * copy keeps the original's character count, which lets an offset found in the
 * folded text be used on the original body — that is how a snippet can be
 * centred on a match the user spelled differently. Multi-character
 * transliterations (æ, ß, þ, ǆ, ǉ, ǌ) belong to {@see Slug}, which builds
 * filenames and is allowed to change length.
 *
 * Deliberately a plain map and not `intl`/`Transliterator`: MemoryDown has no
 * intl dependency and runs on cheap shared hosting. Only lowercase letters are
 * needed, because {@see self::fold()} lowercases first.
 */
final class Text
{
    /** @var array<string, string> */
    private const FOLD = [
        // Latin-1 Supplement.
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ð' => 'd',
        'ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ø' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ý' => 'y', 'ÿ' => 'y',

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
    ];

    /**
     * Lowercase and fold diacritics to their base letter. Character-count
     * preserving, so folded offsets are valid on the original string.
     */
    public static function fold(string $value): string
    {
        return strtr(mb_strtolower($value, 'UTF-8'), self::FOLD);
    }
}
