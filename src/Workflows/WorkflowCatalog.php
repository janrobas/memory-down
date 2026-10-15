<?php

declare(strict_types=1);

namespace MemoryDown\Workflows;

use MemoryDown\Memory\MemoryStore;
use MemoryDown\Memory\PathValidator;
use MemoryDown\Memory\WorkflowDefinition;

/**
 * Read-only view of the workflow definitions.
 *
 * Only the `workflows` category is ever read here, so nothing else in the memory
 * store can leak through this service. Unlike the public writings API there is no
 * per-entry flag that hides anything: the category is the scope and the API token
 * is the boundary. `executable` is reported, never required — a note in the same
 * category is a draft or a description of a process, and the caller is the one
 * that decides what to do with it (docs/WORKFLOWS.md §1).
 *
 * Markdown stays the source of truth: this class reads it, returns the body
 * verbatim as `markdown`, and never exposes internal fields (path, source). The
 * parsed `config` and `instructions` are a convenience over that body — the
 * authority on whether a definition may run is the runner, not this service, so
 * no verdict is published here.
 */
final class WorkflowCatalog
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;

    public function __construct(private readonly MemoryStore $store)
    {
    }

    /**
     * The entries in the workflows category, newest-created first (stable: an
     * edit never bumps a definition above newer ones), optionally restricted to
     * the ones a runner is authorised to execute.
     *
     * @return array{workflows: list<array<string, mixed>>, count: int, total: int, has_more: bool, limit: int, offset: int}
     */
    public function list(int $limit = self::DEFAULT_LIMIT, int $offset = 0, ?bool $executable = null): array
    {
        $limit = min(max(1, $limit), self::MAX_LIMIT);
        $offset = max(0, $offset);

        // Archive state is deliberately not filtered out: an archived definition
        // is paused, which the caller reads from the payload.
        $all = $this->store->list(WorkflowDefinition::CATEGORY, PHP_INT_MAX);
        if (null !== $executable) {
            $all = array_values(array_filter(
                $all,
                static fn (array $doc): bool => WorkflowDefinition::hasTag($doc['tags'] ?? []) === $executable,
            ));
        }

        usort($all, static function (array $a, array $b): int {
            return ((int) ($b['created_ts'] ?? 0) <=> (int) ($a['created_ts'] ?? 0))
                ?: ((int) ($b['updated_ts'] ?? 0) <=> (int) ($a['updated_ts'] ?? 0));
        });

        $total = count($all);
        $page = array_slice($all, $offset, $limit);

        return [
            'workflows' => array_map(self::summary(...), $page),
            'count' => count($page),
            'total' => $total,
            'has_more' => $offset + count($page) < $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * A single entry from the workflows category, with its Markdown body. Returns
     * null when it does not exist or the id could not name a file (including any
     * traversal attempt), so the caller answers 404 for all of them alike.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $id): ?array
    {
        if (!PathValidator::isId($id)) {
            return null;
        }

        $doc = $this->store->read(WorkflowDefinition::CATEGORY, $id);
        if (null === $doc) {
            return null;
        }

        $body = (string) ($doc['body'] ?? '');
        $parsed = WorkflowDefinition::parse($body);

        return self::summary($doc) + [
            'markdown' => $body,
            'config' => $parsed['config'],
            'instructions' => $parsed['instructions'],
        ];
    }

    /**
     * @param array<string, mixed> $doc
     *
     * @return array<string, mixed>
     */
    private static function summary(array $doc): array
    {
        return [
            'id' => $doc['id'],
            'title' => $doc['title'],
            'executable' => WorkflowDefinition::hasTag($doc['tags'] ?? []),
            'archived' => (bool) ($doc['archived'] ?? false),
            'tags' => array_values($doc['tags'] ?? []),
            'created' => $doc['created'] ?? '',
            'updated' => $doc['updated'] ?? '',
        ];
    }
}
