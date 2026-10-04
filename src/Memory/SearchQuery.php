<?php

declare(strict_types=1);

namespace MemoryDown\Memory;

/**
 * Parsed representation of a search query.
 *
 * The free-text query may carry small directives so callers (and AI agents)
 * can filter without a dedicated UI:
 *
 *   tag:project-x     require the exact tag "project-x" (repeatable, AND)
 *   tag:"a b"         require the exact tag "a b" (quote multi-word tags)
 *   is:archived       only archived entries
 *   is:active         only active entries
 *
 * Everything else is treated as normal full-text terms. Explicit parameters
 * passed to {@see SearchEngine::search()} take precedence over directives.
 */
final class SearchQuery
{
    /**
     * @param list<string> $terms
     * @param list<string> $tags
     */
    private function __construct(
        public readonly array $terms,
        public readonly array $tags,
        public readonly ?bool $archived,
    ) {
    }

    public static function parse(string $query): self
    {
        $terms = [];
        $tags = [];
        $archived = null;

        foreach (self::tokenize($query) as $word) {
            if ('' === $word) {
                continue;
            }

            if (preg_match('/^tag:(.+)$/is', $word, $m)) {
                // The value may be a quoted phrase (tag:"a b"); the quotes group
                // a multi-word tag into one value instead of splitting on space.
                $tag = mb_strtolower(trim(self::unquote(trim($m[1]))));
                if ('' !== $tag) {
                    $tags[] = $tag;
                }
                continue;
            }

            if (preg_match('/^is:(archived|active)$/i', $word, $m)) {
                $archived = 'archived' === mb_strtolower($m[1]);
                continue;
            }

            $word = trim($word, "\"'.,;:!?()[]{}");
            if (mb_strlen($word) >= 2) {
                $terms[] = mb_strtolower($word);
            }
        }

        return new self(
            array_values(array_unique($terms)),
            array_values(array_unique($tags)),
            $archived,
        );
    }

    /**
     * Split the query into tokens, keeping quoted phrases intact so that
     * tag:"multi word tag" is one token. Single and double quotes are honoured.
     *
     * @return list<string>
     */
    private static function tokenize(string $query): array
    {
        $tokens = [];
        $length = strlen($query);
        $i = 0;

        while ($i < $length) {
            // Skip whitespace between tokens.
            while ($i < $length && ctype_space($query[$i])) {
                ++$i;
            }
            if ($i >= $length) {
                break;
            }

            $start = $i;
            $quote = '';
            while ($i < $length) {
                $char = $query[$i];
                if ('' !== $quote) {
                    if ($char === $quote) {
                        $quote = '';
                    }
                } elseif ('"' === $char || "'" === $char) {
                    $quote = $char;
                } elseif (ctype_space($char)) {
                    break;
                }
                ++$i;
            }

            $tokens[] = substr($query, $start, $i - $start);
        }

        return $tokens;
    }

    /**
     * Remove one pair of surrounding single or double quotes, if present.
     */
    private static function unquote(string $value): string
    {
        $length = strlen($value);
        if ($length >= 2) {
            $first = $value[0];
            $last = $value[$length - 1];
            if (('"' === $first && '"' === $last) || ("'" === $first && "'" === $last)) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }

    /**
     * Merge explicit parameters on top of the parsed directives. An explicit
     * value always wins; an explicit tag is added to any parsed tags.
     */
    public function withExplicit(?string $tag, ?bool $archived): self
    {
        $tags = $this->tags;
        if (null !== $tag && '' !== trim($tag)) {
            $tags[] = mb_strtolower(trim($tag));
        }

        return new self(
            $this->terms,
            array_values(array_unique($tags)),
            null !== $archived ? $archived : $this->archived,
        );
    }

    /**
     * True when there is nothing to match on (no terms and no tags).
     */
    public function isEmpty(): bool
    {
        return [] === $this->terms && [] === $this->tags;
    }
}
