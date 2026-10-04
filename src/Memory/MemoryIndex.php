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
 * The index is refreshed lazily: a cheap per-file corpus signature (path +
 * size + mtime hash) is compared against the value stored in the database, and
 * the whole index is rebuilt when it differs. This needs no background
 * workers, which suits ordinary PHP shared hosting.
 */
final class MemoryIndex implements SearchEngine
{
    /**
     * FTS layout version. Bump when the columns change: the index is
     * disposable, so a version mismatch just drops and rebuilds it from the
     * Markdown on the next search.
     */
    private const SCHEMA_VERSION = '2';

    /**
     * FTS5 column list. `archived` is kept last and UNINDEXED so the existing
     * positional references (bm25, snippet column 4 = body) stay valid.
     */
    private const FTS_COLUMNS = 'id UNINDEXED, category UNINDEXED, title, tags, body, archived UNINDEXED, tokenize=\'unicode61\'';

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

        // With the index unavailable, always scan the Markdown directly.
        if (!$this->available) {
            return $this->fallback->search($query, $category, $limit, $includeBody, $tag, $archived);
        }

        $limit = min(max(1, $limit), 200);
        // Fetch a wider window: the exact tag check can discard prefix
        // over-matches after the query, so they must not crowd out real hits.
        $candidates = min(max($limit * 4, 50), 200);

        try {
            $this->ensureFresh();

            // Free-text terms match title/tags/body; tag: directives are scoped
            // to the tags column. Either group alone is enough; both AND.
            $match = $this->matchExpression($q->terms, $q->tags);
            if ('' === $match) {
                return $this->fallback->search($query, $category, $limit, $includeBody, $tag, $archived);
            }

            $sql = 'SELECT id, category, bm25(memories) AS rank, '
                . "snippet(memories, 4, '', '\u{2026}', '\u{2026}', 24) AS snippet "
                . 'FROM memories WHERE memories MATCH :match';
            if (null !== $category) {
                $sql .= ' AND category = :category';
            }
            if (null !== $q->archived) {
                // Pushed into SQL so LIMIT applies after filtering; the flag is
                // stored as text '1'/'0' in the UNINDEXED column.
                $sql .= ' AND archived = :archived';
            }
            $sql .= ' ORDER BY bm25(memories) LIMIT :limit';

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':match', $match, \PDO::PARAM_STR);
            if (null !== $category) {
                $stmt->bindValue(':category', $category, \PDO::PARAM_STR);
            }
            if (null !== $q->archived) {
                $stmt->bindValue(':archived', $q->archived ? '1' : '0', \PDO::PARAM_STR);
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
                'INSERT INTO memories (id, category, title, tags, body, archived) VALUES (:id, :category, :title, :tags, :body, :archived)'
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
                    ':archived' => !empty($doc['archived']) ? '1' : '0',
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
            $this->pdo = $pdo;
            $this->ensureSchema();
            $this->available = true;
        } catch (\Throwable $e) {
            $this->logger->warning('memory.index.unavailable', ['message' => $e->getMessage()]);
            $this->pdo = null;
            $this->available = false;
        }
    }

    /**
     * Create the FTS table, or drop and recreate it when the layout version
     * changed. The index is disposable, so a rebuild from the Markdown is safe
     * and loses nothing.
     */
    private function ensureSchema(): void
    {
        if (self::SCHEMA_VERSION === $this->getMeta('schema_version')) {
            return;
        }

        $this->pdo->exec('DROP TABLE IF EXISTS memories');
        $this->pdo->exec('CREATE VIRTUAL TABLE memories USING fts5(' . self::FTS_COLUMNS . ')');
        $this->setMeta('schema_version', self::SCHEMA_VERSION);
        // The corpus is unchanged but the new table is empty: clear the stored
        // signature so the next search triggers a full rebuild.
        $this->setMeta('signature', '');
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
     * Build an FTS5 MATCH expression.
     *
     * Free-text terms are prefix-matched against every indexed column
     * (title, tags, body). "tag:" directives are restricted to the tags
     * column, tokenised so tags containing punctuation still match; the exact
     * tag check in {@see hasAllTags()} runs afterwards because FTS prefix
     * matching is deliberately broader than tag equality.
     *
     * Returns '' when there is nothing indexable (the caller then scans files).
     *
     * @param list<string> $terms
     * @param list<string> $tags
     */
    private function matchExpression(array $terms, array $tags): string
    {
        $groups = [];

        $textClauses = [];
        foreach ($terms as $term) {
            $textClauses[] = '"' . str_replace('"', '""', $term) . '"*';
        }
        if ([] !== $textClauses) {
            $groups[] = '(' . implode(' OR ', $textClauses) . ')';
        }

        $tagClauses = [];
        foreach ($tags as $tag) {
            foreach (preg_split('/[^a-z0-9]+/', $tag, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                $tagClauses[] = 'tags : "' . str_replace('"', '""', $token) . '"*';
            }
        }
        if ([] !== $tagClauses) {
            $groups[] = '(' . implode(' AND ', $tagClauses) . ')';
        }

        return implode(' AND ', $groups);
    }
}
