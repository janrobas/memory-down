<?php

declare(strict_types=1);

namespace MemoryDown\Publish;

use MemoryDown\Memory\MemoryStore;
use MemoryDown\Memory\PathValidator;

/**
 * Read-only view of the public writings.
 *
 * Only entries in the "writings" category that carry `public: true` are ever
 * read or returned. Nothing outside that folder can leak through this service,
 * so a stray `public: true` on a private memory is harmless. Markdown stays the
 * source of truth: this class only reads it, and never returns internal fields
 * (path, source, timestamps).
 */
final class Publications
{
    /** The single category whose entries may be published. */
    public const CATEGORY = 'writings';

    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;

    public function __construct(private readonly MemoryStore $store)
    {
    }

    /**
     * List public writings, newest-created first (stable: an edit never bumps an
     * entry above newer ones).
     *
     * @return array{writings: list<array<string, mixed>>, count: int, total: int, has_more: bool, limit: int, offset: int}
     */
    public function list(int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $limit = min(max(1, $limit), self::MAX_LIMIT);
        $offset = max(0, $offset);

        // archive state is irrelevant here: archived-but-public is still served.
        $all = $this->store->list(self::CATEGORY, PHP_INT_MAX, null, null, true);
        usort($all, static function (array $a, array $b): int {
            return ((int) ($b['created_ts'] ?? 0) <=> (int) ($a['created_ts'] ?? 0))
                ?: ((int) ($b['updated_ts'] ?? 0) <=> (int) ($a['updated_ts'] ?? 0));
        });

        $total = count($all);
        $page = array_slice($all, $offset, $limit);

        return [
            'writings' => array_map(self::summary(...), $page),
            'count' => count($page),
            'total' => $total,
            'has_more' => $offset + count($page) < $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * A single public writing, including its Markdown body. Returns null when it
     * does not exist, is not public, or is not in the writings category.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $id): ?array
    {
        if (!PathValidator::isId($id)) {
            return null;
        }

        $doc = $this->store->read(self::CATEGORY, $id);
        if (null === $doc || empty($doc['public'])) {
            return null;
        }

        return [
            'id' => $doc['id'],
            'title' => $doc['title'],
            'archived' => (bool) ($doc['archived'] ?? false),
            'tags' => array_values($doc['tags'] ?? []),
            'created' => $doc['created'] ?? '',
            'updated' => $doc['updated'] ?? '',
            'markdown' => (string) ($doc['body'] ?? ''),
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
            'archived' => (bool) ($doc['archived'] ?? false),
            'tags' => array_values($doc['tags'] ?? []),
            'created' => $doc['created'] ?? '',
            'updated' => $doc['updated'] ?? '',
        ];
    }
}
