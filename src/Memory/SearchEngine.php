<?php

declare(strict_types=1);

namespace MemoryDown\Memory;

/**
 * Full-text search over the Markdown memory corpus.
 *
 * Implementations must never become the source of truth: they read the .md
 * files (directly or through a disposable index) and return ranked documents.
 */
interface SearchEngine
{
    /**
     * Full-text search over the memory corpus, with optional tag and archive
     * filters. Archived entries are included by default and ranked after
     * active ones.
     *
     * @param string      $query       free text; may contain "tag:x" / "is:archived" directives
     * @param string|null $category    restrict to one category
     * @param int         $limit       maximum results
     * @param bool        $includeBody include the full body of each result
     * @param string|null $tag         restrict to an exact tag (case-insensitive)
     * @param bool|null   $archived    true = only archived, false = only active, null = all
     *
     * @return list<array<string, mixed>> ranked results, each with "score", "snippet" and "rank"
     */
    public function search(string $query, ?string $category = null, int $limit = 10, bool $includeBody = false, ?string $tag = null, ?bool $archived = null): array;

    /**
     * Rebuild any derived index from the Markdown files. A no-op for engines
     * that search the files directly.
     */
    public function rebuild(): void;
}
