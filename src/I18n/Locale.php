<?php

declare(strict_types=1);

namespace MemoryDown\I18n;

/**
 * The languages the admin UI is available in, and how one is chosen.
 *
 * The scope is deliberately narrow: this is the language of the HUMAN interface
 * (pages, buttons, notices, consent screen). It never changes what is stored:
 * memory stays Markdown in whatever language its author wrote, category names
 * and tags stay as they are, and the MCP tool descriptions stay English because
 * they are read by agents, not by people.
 *
 * Nothing here is configuration stored per account. The choice lives in one
 * cookie, so it works on the sign-in page too (where no account is known yet),
 * and it follows the browser rather than the account.
 */
final class Locale
{
    /** The complete list of supported languages, in the order they are offered. */
    public const NAMES = [
        'sl' => 'Slovenščina',
        'en' => 'English',
        'it' => 'Italiano',
    ];

    /**
     * The language a key is looked up in when the chosen one has no translation
     * for it. Also the last-resort default: it is the only catalogue that is
     * guaranteed complete.
     */
    public const FALLBACK = 'en';

    /** Where the choice is remembered between requests. */
    public const COOKIE = 'memorydown_lang';

    public const COOKIE_TTL = 31536000; // one year

    /** @return list<string> */
    public static function supported(): array
    {
        return array_keys(self::NAMES);
    }

    public static function isSupported(string $code): bool
    {
        return isset(self::NAMES[$code]);
    }

    /**
     * Reduce anything a client sends ("it", "IT-it", " sl ") to a supported code,
     * or '' when it is not one of them.
     */
    public static function normalize(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $code = strtolower(trim((string) $value));
        if ('' === $code) {
            return '';
        }
        // A region or script subtag says nothing this UI distinguishes, so
        // "it-CH" is Italian and "sl-SI" is Slovenian. Not "sr-Latn": that is a
        // different language and stays unsupported.
        $primary = preg_split('/[-_]/', $code)[0] ?? '';

        return self::isSupported($primary) ? $primary : '';
    }

    /**
     * The language of one request: what the person chose, else what their
     * browser asks for, else the instance default.
     */
    public static function resolve(?string $cookie, ?string $acceptLanguage, string $default): string
    {
        $chosen = self::normalize($cookie);
        if ('' !== $chosen) {
            return $chosen;
        }

        $negotiated = self::fromAcceptLanguage($acceptLanguage);
        if ('' !== $negotiated) {
            return $negotiated;
        }

        $fallback = self::normalize($default);

        return '' !== $fallback ? $fallback : self::FALLBACK;
    }

    /**
     * The best supported language an Accept-Language header asks for, or ''.
     *
     * Quality values are honoured ("it;q=0.9, en;q=0.5"), and a header naming
     * only languages this UI does not have returns '' rather than guessing.
     */
    public static function fromAcceptLanguage(?string $header): string
    {
        if (null === $header || '' === trim($header)) {
            return '';
        }

        $best = '';
        $bestQuality = -1.0;
        foreach (explode(',', $header) as $part) {
            $pieces = explode(';', trim($part));
            $tag = self::normalize($pieces[0] ?? '');
            if ('' === $tag) {
                continue;
            }
            $quality = 1.0;
            foreach (array_slice($pieces, 1) as $parameter) {
                $parameter = trim($parameter);
                if (str_starts_with($parameter, 'q=')) {
                    $quality = (float) substr($parameter, 2);
                }
            }
            if ($quality > $bestQuality) {
                $best = $tag;
                $bestQuality = $quality;
            }
        }

        return $best;
    }

    /**
     * The language of the current request, read from the superglobals.
     *
     * Only the web layer calls this; everything else takes a locale explicitly.
     */
    public static function fromRequest(string $default): string
    {
        $cookie = $_COOKIE[self::COOKIE] ?? null;
        $accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;

        return self::resolve(
            is_scalar($cookie) ? (string) $cookie : null,
            is_scalar($accept) ? (string) $accept : null,
            $default,
        );
    }

    /**
     * A Set-Cookie value remembering this choice, or '' for an unsupported code.
     *
     * Same cookie policy as the session: not readable by script, not sent on a
     * cross-site request, and Secure wherever the instance is in production.
     */
    public static function cookieHeader(string $code, bool $secure): string
    {
        if (!self::isSupported($code)) {
            return '';
        }

        return self::COOKIE . '=' . $code
            . '; Path=/; Max-Age=' . self::COOKIE_TTL
            . '; SameSite=Lax'
            . ($secure ? '; Secure' : '')
            . '; HttpOnly';
    }

    /**
     * Which plural form a language uses for a count.
     *
     * Slovenian has four (1, 2, 3–4, everything else), English and Italian have
     * two. The Catalan-style "many" forms of other languages are not needed by
     * anything supported here, so the fallback is the two-form rule.
     *
     * @return string one|two|few|other
     */
    public static function pluralForm(string $locale, int $count): string
    {
        $n = abs($count);

        return match (self::normalize($locale) ?: self::FALLBACK) {
            'sl' => match (true) {
                1 === $n % 100 => 'one',
                2 === $n % 100 => 'two',
                in_array($n % 100, [3, 4], true) => 'few',
                default => 'other',
            },
            default => 1 === $n ? 'one' : 'other',
        };
    }
}
