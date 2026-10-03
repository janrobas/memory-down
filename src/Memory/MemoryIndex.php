<?php

declare(strict_types=1);

namespace MemoryDown\Memory;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Disposable full-text index over the Markdown corpus, backed by SQLite FTS5.
 *
 * Markdown remains the source of truth: this class only ever reads .md files
 * and writes a rebuildable cache. If SQLite/FTS5 is unavailable (for example a
 * shared host without pdo_sqlite) the constructor marks the index unusable and
 * every search transparently falls back to {@see MemorySearch} (direct scan).
 *
 * The index is refreshed lazily: a cheap corpus signature (file count + newest
 * mtime) is compared against the value stored in the database, and the whole
 * index is rebuilt when it differs. This needs no background workers, which
 * suits ordinary PHP shared hosting.
 */
final class MemoryIndex implements SearchEngine
{
    private ?\PDO $pdo = null;
    private bool $available = false;
    private MemorySearch $fallback;

    public function __construct(
        private readonly MemoryStore $store,
        private readonly string $dbPath,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly bool $enabled = true,
    ) {
        $this->fallback = new MemorySearch($store);
        $this->open();
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function path(): string
    {
        return $this->dbPath;
    }

    /**
     * Diagnostics for /health. Never exposes anything secret.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return [
            'enabled' => $this->enabled,
            'engine' => $this->available ? 'sqlite-fts5' : 'direct',
            'available' => $this->available,
            'path' => $this->dbPath,
            'indexed' => $this->available ? $this->indexedCount() : 0,
        ];
    }

    public function search(string $query, ?string $category = null, int $limit = 10, bool $includeBody = false, ?string $tag = null, ?bool $archived = null): array
    {
        $q = SearchQuery::parse($query)->withExplicit($tag, $archived);
        if ($q->isEmpty()) {
            return [];
        }

        // No text terms (tag-only / filter-only): the direct engine scans and
        // filters exactly, so there is nothing for FTS to match on.
        if (!$this->available || [] === $q->terms) {
            return $this->fallback->search($query, $category, $limit, $includeBody, $tag, $archived);
        }

        $limit = min(max(1, $limit), 50);
        // Fetch a wider window: archived entries are demoted after reading, so
        // they must not crowd active hits out of a tight LIMIT.
        $candidates = min(max($limit * 4, 50), 200);

        try {
            $this->ensureFresh();

            $match = $this->matchExpression($q->terms);
            $sql = 'SELECT id, category, bm25(memories) AS rank, '
                . "snippet(memories, 4, '', '\u{2026}', '\u{2026}', 24) AS snippet "
                . 'FROM memories WHERE memories MATCH :match';
            if (null !== $category) {
                $sql .= ' AND category = :category';
            }
            $sql .= ' ORDER BY bm25(memories) LIMIT :limit';

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':match', $match, \PDO::PARAM_STR);
            if (null !== $category) {
                $stmt->bindValue(':category', $category, \PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $candidates, \PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $this->logger->warning('memory.index.query_failed', ['message' => $e->getMessage()]);

            return $this->fallback->search($query, $category, $limit, $includeBody, $tag, $archived);
        }

        $results = [];
        foreach ($rows as $row) {
            $doc = $this->store->read((string) $row['category'], (string) $row['id']);
            if (null === $doc) {
                continue;
            }
            if (null !== $q->archived && (bool) ($doc['archived'] ?? false) !== $q->archived) {
                continue;
            }
            if ([] !== $q->tags && !$this->hasAllTags($doc, $q->tags)) {
                continue;
            }
            $doc['score'] = round(-1 * (float) $row['rank'], 4);
            $doc['snippet'] = trim((string) $row['snippet']);
            if (!$includeBody) {
                unset($doc['body']);
            }
            $results[] = $doc;
        }

        usort($results, static fn (array $a, array $b): int => ((int) ($a['archived'] ?? false) <=> (int) ($b['archived'] ?? false))
            ?: (($b['score'] ?? 0) <=> ($a['score'] ?? 0)));
        $results = array_slice($results, 0, $limit);

        foreach ($results as $i => $result) {
            $results[$i]['rank'] = $i + 1;
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $doc
     * @param list<string>         $tags
     */
    private function hasAllTags(array $doc, array $tags): bool
    {
        $docTags = array_map('strtolower', array_map('strval', $doc['tags'] ?? []));
        foreach ($tags as $tag) {
            if (!in_array($tag, $docTags, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Rebuild the whole index from the Markdown files.
     */
    public function rebuild(): void
    {
        if (!$this->available) {
            return;
        }

        try {
            $this->pdo->beginTransaction();
            $this->pdo->exec('DELETE FROM memories');

            $insert = $this->pdo->prepare(
                'INSERT INTO memories (id, category, title, tags, body) VALUES (:id, :category, :title, :tags, :body)'
            );

            foreach ($this->store->collectFiles() as $file) {
                $category = basename(dirname($file));
                $id = substr(basename($file), 0, -3);
                $doc = $this->store->read($category, $id);
                if (null === $doc) {
                    continue;
                }
                $insert->execute([
                    ':id' => $id,
                    ':category' => $category,
                    ':title' => (string) $doc['title'],
                    ':tags' => implode(' ', $doc['tags']),
                    ':body' => (string) $doc['body'],
                ]);
            }

            $this->setMeta('signature', $this->signature());
            $this->pdo->commit();
            $this->logger->info('memory.index.rebuilt', ['entries' => $this->indexedCount()]);
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->logger->warning('memory.index.rebuild_failed', ['message' => $e->getMessage()]);
        }
    }

    private function open(): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            return;
        }

        $dir = dirname($this->dbPath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        try {
            $pdo = new \PDO('sqlite:' . $this->dbPath);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec('CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
            $pdo->exec(
                'CREATE VIRTUAL TABLE IF NOT EXISTS memories USING fts5('
                . "id UNINDEXED, category UNINDEXED, title, tags, body, tokenize='unicode61'"
                . ')'
            );
            $this->pdo = $pdo;
            $this->available = true;
        } catch (\Throwable $e) {
            $this->logger->warning('memory.index.unavailable', ['message' => $e->getMessage()]);
            $this->pdo = null;
            $this->available = false;
        }
    }

    private function ensureFresh(): void
    {
        if ($this->signature() !== $this->getMeta('signature')) {
            $this->rebuild();
        }
    }

    /**
     * Cheap corpus fingerprint: every .md file's path, size and mtime hashed.
     *
     * Deliberately not just "count + newest mtime": a delete plus an insert can
     * restore the count, and files written within the same second share an
     * mtime, which would otherwise hide a change from ensureFresh().
     */
    private function signature(): string
    {
        $root = $this->store->paths()->root();
        $parts = [];
        foreach ($this->store->collectFiles() as $file) {
            $relative = substr($file, strlen($root) + 1);
            $parts[] = $relative . ':' . (int) @filesize($file) . ':' . (int) @filemtime($file);
        }
        sort($parts);

        return md5(implode("\n", $parts));
    }

    private function getMeta(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM meta WHERE key = :key');
        $stmt->execute([':key' => $key]);
        $value = $stmt->fetchColumn();

        return false === $value ? null : (string) $value;
    }

    private function setMeta(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO meta (key, value) VALUES (:key, :value) '
            . 'ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $stmt->execute([':key' => $key, ':value' => $value]);
    }

    private function indexedCount(): int
    {
        try {
            return (int) $this->pdo->query('SELECT COUNT(*) FROM memories')->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param list<string> $tokens
     */
    private function matchExpression(array $tokens): string
    {
        $clauses = [];
        foreach ($tokens as $token) {
            $clauses[] = '"' . str_replace('"', '""', $token) . '"*';
        }

        return implode(' OR ', $clauses);
    }
}
