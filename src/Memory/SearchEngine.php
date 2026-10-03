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
     * @return list<array<string, mixed>> ranked results, each with "score", "snippet" and "rank"
     */
    public function search(string $query, ?string $category = null, int $limit = 10, bool $includeBody = false): array;

    /**
     * Rebuild any derived index from the Markdown files. A no-op for engines
     * that search the files directly.
     */
    public function rebuild(): void;
}
