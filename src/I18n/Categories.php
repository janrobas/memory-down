<?php

declare(strict_types=1);

namespace MemoryDown\I18n;

/**
 * The display name of a memory category.
 *
 * A category is a directory name — `preferences`, `notes`, `writings` — and that
 * name is data: it is in the URL, in the `category` argument of every API call,
 * in `data-*` attributes the editor compares against, in the MCP tools and in the
 * backup. None of that is ever translated.
 *
 * Only the label a person reads is: the sidebar group, the chips, the "move to"
 * picker, the breadcrumb, the trash list. A category with no label of its own —
 * one somebody added by hand, or a custom folder — is shown exactly as it is
 * stored, so the mapping can never invent a name for a directory that does not
 * exist and can never show a bare catalogue key.
 */
final class Categories
{
    /** The catalogue key prefix: `category.preferences`, `category.notes`, … */
    public const PREFIX = 'category.';

    public static function label(string $slug, Translator $translator): string
    {
        $key = self::PREFIX . $slug;

        return $translator->has($key) ? $translator->t($key) : $slug;
    }

    /**
     * slug => label, for every category a page is about to draw.
     *
     * @param list<string> $slugs
     *
     * @return array<string, string>
     */
    public static function labels(array $slugs, Translator $translator): array
    {
        $labels = [];
        foreach ($slugs as $slug) {
            $labels[$slug] = self::label($slug, $translator);
        }

        return $labels;
    }
}
