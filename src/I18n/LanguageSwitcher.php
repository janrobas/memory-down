<?php

declare(strict_types=1);

namespace MemoryDown\I18n;

/**
 * The language chooser, in one place because it is rendered in three different
 * kinds of page: the editor's ⋯ menu, the sign-in/setup pages, and the OAuth
 * consent screen (which builds its own HTML outside the view layer, and outside
 * the admin CSP).
 *
 * Deliberately a row of plain links rather than a form: picking a language
 * writes one preference cookie and reloads the page, so it works with no
 * JavaScript at all and cannot be the thing that breaks a sign-in.
 *
 * The endonym is always shown in its own language ("Slovenščina", "English",
 * "Italiano") and tagged with its `lang`, because the point of the chooser is to
 * be readable to somebody who cannot read the language currently on screen.
 */
final class LanguageSwitcher
{
    public static function html(Translator $translator, string $back, string $class = 'lang-links'): string
    {
        $current = $translator->locale();
        $links = [];

        foreach (Locale::NAMES as $code => $name) {
            $active = $code === $current;
            $links[] = sprintf(
                '<a class="lang-link%s" lang="%s" hreflang="%s" href="%s"%s>%s</a>',
                $active ? ' is-active' : '',
                $code,
                $code,
                self::escape(self::href($code, $back)),
                $active ? ' aria-current="true"' : '',
                self::escape($name),
            );
        }

        return sprintf(
            '<div class="%s" role="group" aria-label="%s">%s</div>',
            self::escape($class),
            self::escape($translator->t('app.language')),
            implode('', $links),
        );
    }

    /** The URL one link points at: the language endpoint, remembering where to return. */
    public static function href(string $code, string $back): string
    {
        $query = 'lang=' . rawurlencode($code);
        if ('' !== $back) {
            $query .= '&back=' . rawurlencode($back);
        }

        return '/ui/language?' . $query;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
