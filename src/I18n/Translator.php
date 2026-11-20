<?php

declare(strict_types=1);

namespace MemoryDown\I18n;

/**
 * The translator the admin UI renders through.
 *
 * Catalogues are plain PHP arrays in `lang/<code>.php`, keyed by a dotted name.
 * Not gettext: a .mo catalogue needs a compiled locale on the host, and cheap
 * shared hosting is exactly where that is missing or mysteriously unset. An
 * array needs nothing but PHP itself, and a missing key is visible in the file
 * rather than in a silent fallback to msgid.
 *
 * A value is either a string or, for a count, a map of plural forms
 * ("one", "two", "few", "other"). Placeholders are `{name}` and are substituted
 * literally, after the form has been chosen; the caller escapes the result.
 *
 * English is the fallback: a key missing from another catalogue is answered in
 * English rather than as a bare key, so a half-finished translation is a mixed
 * page instead of a broken one. A key missing everywhere returns itself, which
 * tests catch and a browser shows plainly.
 */
final class Translator
{
    /**
     * Strings the browser needs too. app.js renders a few pieces of the UI
     * itself (list rows, status line, definition verdict, confirms), so those
     * keys travel with the page as JSON; everything else is rendered on the
     * server and never crosses over. A key listed here but absent from the
     * catalogue still falls back, exactly like the server path.
     */
    public const CLIENT_KEYS = [
        'app.new_memory',
        'app.no_memories',
        'app.reindex',
        'app.collapse_all',
        'app.expand_all',
        'badge.private',
        'badge.archived',
        'badge.public',
        'badge.new',
        'definition.ok',
        'definition.refuse',
        'js.sign_out',
        'js.reindex_busy',
        'js.reindexed',
        'js.index_unavailable',
        'js.reindex_failed',
        'js.saving',
        'js.saved',
        'js.editing',
        'js.no_active',
        'js.no_archived',
        'js.no_matches',
        'js.results',
        'js.new_memory_in',
        'js.could_not_load',
        'js.content_empty',
        'js.save_failed',
        'js.network_error',
        'js.delete_failed',
        'js.move_failed',
        'js.could_not_move',
        'js.moved',
        'js.moved_to_trash',
        'js.delete_confirm',
        'js.preview_unavailable',
        'js.private_workflow',
        'js.private_workflow_tag',
    ];

    /** @var array<string, string|array<string, string>> */
    private readonly array $messages;

    private readonly string $locale;

    public function __construct(string $locale)
    {
        $chosen = Locale::normalize($locale);
        $this->locale = '' !== $chosen ? $chosen : Locale::FALLBACK;

        // The fallback catalogue first, the chosen language over it: one array
        // lookup per string, and no "missing key" branch in t().
        $this->messages = array_replace(
            self::catalogue(Locale::FALLBACK),
            self::catalogue($this->locale),
        );
    }

    /** Build one from the current request (cookie, then Accept-Language, then the instance default). */
    public static function forRequest(string $default): self
    {
        return new self(Locale::fromRequest($default));
    }

    /**
     * @return array<string, string|array<string, string>>
     */
    public static function catalogue(string $locale): array
    {
        $code = Locale::normalize($locale);
        if ('' === $code) {
            return [];
        }
        $file = __DIR__ . '/lang/' . $code . '.php';

        return is_file($file) ? (array) require $file : [];
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /** The value of the HTML `lang` attribute for this catalogue. */
    public function htmlLang(): string
    {
        return $this->locale;
    }

    /**
     * Translate one key, substituting `{placeholders}`.
     *
     * @param array<string, scalar> $params
     */
    public function t(string $key, array $params = []): string
    {
        $value = $this->messages[$key] ?? $key;

        if (is_array($value)) {
            $count = (int) ($params['n'] ?? $params['count'] ?? 0);
            $form = Locale::pluralForm($this->locale, $count);
            $value = $value[$form] ?? $value['other'] ?? $value['one'] ?? $key;
        }

        if ([] === $params) {
            return $value;
        }

        $replacements = [];
        foreach ($params as $name => $replacement) {
            $replacements['{' . $name . '}'] = self::stringify($replacement);
        }

        return strtr($value, $replacements);
    }

    public function has(string $key): bool
    {
        return isset($this->messages[$key]);
    }

    /**
     * The subset of strings app.js needs, ready to be embedded in the page.
     *
     * @return array<string, string>
     */
    public function clientBundle(): array
    {
        $bundle = [];
        foreach (self::CLIENT_KEYS as $key) {
            $bundle[$key] = $this->t($key);
        }

        return $bundle;
    }

    /** The same key, already HTML-escaped, for use directly in a template. */
    public function e(string $key, array $params = []): string
    {
        return htmlspecialchars($this->t($key, $params), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param mixed $value */
    private static function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value);
    }
}
