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

        foreach (preg_split('/\s+/', trim($query)) ?: [] as $word) {
            $word = trim($word);
            if ('' === $word) {
                continue;
            }

            if (preg_match('/^tag:(.+)$/i', $word, $m)) {
                $tag = strtolower(trim($m[1], "\"'"));
                if ('' !== $tag) {
                    $tags[] = $tag;
                }
                continue;
            }

            if (preg_match('/^is:(archived|active)$/i', $word, $m)) {
                $archived = 'archived' === strtolower($m[1]);
                continue;
            }

            $word = trim($word, "\"'.,;:!?()[]{}");
            if (mb_strlen($word) >= 2) {
                $terms[] = strtolower($word);
            }
        }

        return new self(
            array_values(array_unique($terms)),
            array_values(array_unique($tags)),
            $archived,
        );
    }

    /**
     * Merge explicit parameters on top of the parsed directives. An explicit
     * value always wins; an explicit tag is added to any parsed tags.
     */
    public function withExplicit(?string $tag, ?bool $archived): self
    {
        $tags = $this->tags;
        if (null !== $tag && '' !== trim($tag)) {
            $tags[] = strtolower(trim($tag));
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
