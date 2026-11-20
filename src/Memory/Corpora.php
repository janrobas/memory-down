<?php

declare(strict_types=1);

namespace MemoryDown\Memory;

/**
 * One or more Markdown corpora, addressed by owner id.
 *
 * A corpus is always somebody's directory tree, and the owner id is the only
 * thing that selects it. This interface exists so {@see \MemoryDown\Mcp\MemoryTools}
 * can be written once for both MCP endpoints:
 *
 *  - the ordinary `/mcp` connector resolves to exactly one owner — the account
 *    whose bearer token is being served — so a tool call cannot reach another
 *    corpus even if it asks for one;
 *  - the admin connector (`/mcp/all`) resolves to every enabled account, which
 *    is what makes cross-account search and writes possible.
 *
 * Implementations must never invent an owner: `owners()` is the complete set a
 * call may touch, and `store()`/`search()` must refuse anything outside it.
 */
interface Corpora
{
    /**
     * Every owner a call may touch, sorted. Never empty for a reachable
     * connector: an implementation that can see nothing returns [].
     *
     * @return list<string>
     */
    public function owners(): array;

    /** Whether $owner is one of {@see owners()}. */
    public function has(string $owner): bool;

    /** The Markdown store of $owner. @throws \InvalidArgumentException when not visible */
    public function store(string $owner): MemoryStore;

    /** The search engine of $owner. @throws \InvalidArgumentException when not visible */
    public function search(string $owner): SearchEngine;
}
