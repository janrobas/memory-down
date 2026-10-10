<?php

declare(strict_types=1);

namespace MemoryDown\Memory;

use MemoryDown\Support\FileLogger;
use Psr\Log\LoggerInterface;

/**
 * CRUD over Markdown memory files.
 *
 * Markdown files are the source of truth. This class never stores memory
 * anywhere else; every operation reads or writes .md files inside
 * $memoryRoot/{category}/{id}.md with a small frontmatter block.
 */
final class MemoryStore
{
    private const SOURCE = 'chatgpt';

    private PathValidator $paths;

    public function __construct(
        string $memoryRoot,
        private readonly string $authDataDir,
        private readonly LoggerInterface $logger = new \Psr\Log\NullLogger(),
    ) {
        $this->paths = new PathValidator($memoryRoot);
        $this->ensureDefaults();
    }

    public function paths(): PathValidator
    {
        return $this->paths;
    }

    public function ensureDefaults(): void
    {
        foreach (PathValidator::DEFAULT_CATEGORIES as $category) {
            $dir = $this->paths->categoryDir($category);
            if (null === $dir) {
                continue;
            }
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
    }

    /**
     * Write a new memory entry. Returns the created document.
     *
     * @param string[] $tags
     *
     * @return array<string, mixed>
     */
    public function create(
        string $content,
        string $title,
        string $category,
        array $tags = [],
        string $id = '',
        bool $archived = false,
    ): array {
        $category = self::normalizeCategory($category);
        $dir = $this->paths->categoryDir($category);
        if (null === $dir) {
            throw new \InvalidArgumentException("Invalid category: {$category}");
        }
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create category directory: {$category}");
        }

        $id = $id !== '' ? $id : $this->newId($title);
        $file = $this->paths->file($category, $id);
        if (null === $file) {
            throw new \InvalidArgumentException("Invalid memory id: {$id}");
        }
        if (is_file($file)) {
            throw new \RuntimeException("Memory entry already exists: {$category}/{$id}.md");
        }

        $now = self::nowTimestamp();
        $frontmatter = [
            'type' => $category,
            'tags' => array_values(array_unique(array_filter($tags))),
            'created' => $now,
            'updated' => $now,
            'source' => self::SOURCE,
            'id' => $id,
        ];
        if ($archived) {
            $frontmatter['archived'] = true;
        }
        $body = self::bodyWithTitle($title, $content);
        $raw = Frontmatter::render($frontmatter) . "\n\n" . $body . "\n";

        if (false === $this->writeFile($file, $raw)) {
            throw new \RuntimeException("Failed to write memory file: {$category}/{$id}.md");
        }

        $this->logger->info('memory.created', ['id' => $id, 'category' => $category]);

        return $this->read($category, $id);
    }

    /**
     * Find the best existing entry that represents the same memory, to avoid
     * obvious duplicates. Matches on (title + category) or identical content.
     *
     * @param string[] $tags
     */
    public function findDuplicate(string $content, string $title, string $category): ?array
    {
        $category = self::normalizeCategory($category);
        $candidates = [];

        $titleKey = strtolower(trim(preg_replace('/\s+/', ' ', $title) ?? ''));
        if ('' !== $titleKey) {
            foreach ($this->list($category, 200) as $doc) {
                $docTitle = strtolower(trim(preg_replace('/\s+/', ' ', $doc['title'] ?? '') ?? ''));
                if ('' !== $docTitle && $docTitle === $titleKey) {
                    $candidates[] = $doc;
                }
            }
        }

        $contentKey = strtolower(trim(preg_replace('/\s+/', ' ', $content) ?? ''));
        if ('' !== $contentKey && [] === $candidates) {
            foreach ($this->list($category, 200) as $doc) {
                $docBody = strtolower(trim(preg_replace('/\s+/', ' ', $doc['body'] ?? '') ?? ''));
                if ('' !== $docBody && $docBody === $contentKey) {
                    $candidates[] = $doc;
                }
            }
        }

        return $candidates[0] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(string $category, string $id): ?array
    {
        $file = $this->paths->file(self::normalizeCategory($category), $id);
        if (null === $file || !is_file($file)) {
            return null;
        }

        $raw = file_get_contents($file);
        if (false === $raw) {
            return null;
        }

        return $this->parseDocument($category, $id, $raw);
    }

    /**
     * @param array{content?: string, title?: string, tags?: string[], category?: string, archived?: bool} $changes
     *
     * @return array<string, mixed>
     */
    public function update(string $category, string $id, array $changes): array
    {
        $current = $this->read($category, $id);
        if (null === $current) {
            throw new \InvalidArgumentException("Memory entry not found: {$category}/{$id}.md");
        }

        $nextCategory = self::normalizeCategory($changes['category'] ?? $category);
        $nextTitle = (string) ($changes['title'] ?? $current['title'] ?? '');
        $nextTags = array_values(array_unique(array_filter(array_map('strval', $changes['tags'] ?? $current['tags'] ?? []))));
        $nextContent = trim((string) ($changes['content'] ?? $current['body'] ?? ''));
        $nextArchived = array_key_exists('archived', $changes)
            ? (bool) $changes['archived']
            : (bool) ($current['archived'] ?? false);

        $dir = $this->paths->categoryDir($nextCategory);
        if (null === $dir) {
            throw new \InvalidArgumentException("Invalid category: {$nextCategory}");
        }
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create category directory: {$nextCategory}");
        }

        $file = $this->paths->file($category, $id);
        if (null === $file) {
            throw new \InvalidArgumentException("Invalid memory id: {$id}");
        }

        $frontmatter = [
            'type' => $nextCategory,
            'tags' => $nextTags,
            'created' => (string) ($current['created'] ?? self::nowTimestamp()),
            'updated' => self::nowTimestamp(),
            'source' => (string) ($current['source'] ?? self::SOURCE),
            'id' => $id,
        ];
        if ($nextArchived) {
            $frontmatter['archived'] = true;
        }
        $raw = Frontmatter::render($frontmatter) . "\n\n" . self::bodyWithTitle($nextTitle, $nextContent) . "\n";

        if ($nextCategory === $category) {
            if (false === $this->writeFile($file, $raw)) {
                throw new \RuntimeException("Failed to write memory file: {$category}/{$id}.md");
            }
        } else {
            $nextFile = $this->paths->file($nextCategory, $id);
            if (null === $nextFile) {
                throw new \InvalidArgumentException("Invalid category: {$nextCategory}");
            }
            if (!is_dir(dirname($nextFile)) && !mkdir(dirname($nextFile), 0775, true)) {
                throw new \RuntimeException("Cannot create category directory: {$nextCategory}");
            }
            if (false === $this->writeFile($nextFile, $raw)) {
                throw new \RuntimeException("Failed to write memory file: {$nextCategory}/{$id}.md");
            }
            @unlink($file);
        }

        $this->logger->info('memory.updated', ['id' => $id, 'category' => $nextCategory]);

        return $this->read($nextCategory, $id) ?? throw new \RuntimeException('Failed to read back memory entry.');
    }

    public function delete(string $category, string $id): bool
    {
        $file = $this->paths->file(self::normalizeCategory($category), $id);
        if (null === $file || !is_file($file)) {
            return false;
        }

        $ok = unlink($file);
        if ($ok) {
            $this->logger->info('memory.deleted', ['id' => $id, 'category' => $category]);
        }

        return $ok;
    }

    /**
     * Write a file atomically: a temporary file in the same directory is
     * written first and then renamed over the target, so readers never see a
     * half-written entry and a crash cannot truncate an existing memory.
     */
    private function writeFile(string $file, string $raw): bool
    {
        $dir = dirname($file);
        $tmp = @tempnam($dir, '.md');
        if (false === $tmp) {
            return false;
        }
        if (false === @file_put_contents($tmp, $raw, LOCK_EX)) {
            @unlink($tmp);

            return false;
        }
        @chmod($tmp, 0664);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    /**
     * List entries, active first then newest first. Optionally restrict to a
     * single archived state and/or a tag (exact, case-insensitive).
     *
     * @return list<array<string, mixed>>
     */
    public function list(?string $category = null, int $limit = 50, ?bool $archived = null, ?string $tag = null): array
    {
        $tag = null !== $tag ? strtolower(trim($tag)) : null;
        $docs = [];
        foreach ($this->collectFiles($category) as $file) {
            $relative = substr($file, strlen($this->paths->root()) + 1);
            $relParts = explode(DIRECTORY_SEPARATOR, $relative);
            if (2 !== count($relParts) || !str_ends_with($relParts[1], '.md')) {
                continue;
            }
            $cat = $relParts[0];
            $id = substr($relParts[1], 0, -3);
            $doc = $this->read($cat, $id);
            if (null === $doc) {
                continue;
            }
            if (null !== $archived && (bool) $doc['archived'] !== $archived) {
                continue;
            }
            if (null !== $tag && '' !== $tag && !in_array($tag, array_map('strtolower', $doc['tags']), true)) {
                continue;
            }
            $docs[] = $doc;
        }

        usort($docs, static function (array $a, array $b): int {
            return ((int) ($a['archived'] ?? false) <=> (int) ($b['archived'] ?? false))
                ?: ((int) ($b['updated_ts'] ?? 0) <=> (int) ($a['updated_ts'] ?? 0));
        });

        return array_slice($docs, 0, max(1, $limit));
    }

    /**
     * Every canonical memory file (`{category}/{id}.md`), newest first.
     *
     * Only files that sit exactly one level deep inside a valid category are
     * returned. Stray files such as a root-level README.md or files nested in
     * subdirectories are ignored here so that count(), list(), search and the
     * index all see exactly the same corpus.
     *
     * @return list<string> absolute paths, newest first
     */
    public function collectFiles(?string $category = null): array
    {
        $root = $this->paths->root();
        $files = [];

        if (null !== $category && !PathValidator::isCategory($category)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.md')) {
                continue;
            }
            $path = $file->getPathname();
            $relative = substr($path, strlen($root) + 1);
            $parts = explode(DIRECTORY_SEPARATOR, $relative);
            if (2 !== count($parts) || !PathValidator::isCategory($parts[0])) {
                continue;
            }
            if (null !== $category && $parts[0] !== $category) {
                continue;
            }
            $files[] = $path;
        }

        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $files;
    }

    public function count(): int
    {
        return count($this->collectFiles());
    }

    /**
     * @return list<string>
     */
    public function categories(): array
    {
        $cats = [];
        foreach (scandir($this->paths->root()) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry || !is_dir($this->paths->root() . DIRECTORY_SEPARATOR . $entry)) {
                continue;
            }
            if (PathValidator::isCategory($entry)) {
                $cats[] = $entry;
            }
        }
        sort($cats);

        return $cats;
    }

    /**
     * Compose the file body: a single H1 for the title, then the content.
     *
     * The store's canonical layout is "# Title" followed by the body. When the
     * supplied content already starts with an H1 (for example an entry written
     * through the admin UI or imported from another tool), that leading heading
     * is treated as the title and not duplicated.
     */
    private static function bodyWithTitle(string $title, string $content): string
    {
        $content = trim($content);
        $heading = $title !== '' ? "# {$title}" : '';

        // Adopt the first existing H1 as the title when none was supplied, and
        // drop any leading H1s (including duplicates left by earlier versions)
        // so exactly one title heading is ever written.
        $content = self::stripLeadingHeadings($content, $adopted);
        if ('' === $title && '' !== $adopted) {
            $heading = '# ' . $adopted;
        }
        if ('' !== $heading) {
            return $content !== '' ? $heading . "\n\n" . $content : $heading;
        }

        return $content;
    }

    /**
     * Remove all leading H1 (`# ...`) lines, returning the remaining body.
     * The first one found is exposed via $first (used as a fallback title).
     */
    private static function stripLeadingHeadings(string $text, ?string &$first = null): string
    {
        $text = ltrim($text);
        while ('' !== $text && preg_match('/^#\s+(.+?)\s*(?:\r?\n|$)/', $text, $m)) {
            if (null === $first) {
                $first = trim($m[1]);
            }
            $text = ltrim(substr($text, strlen($m[0])));
        }

        return $text;
    }

    private static function normalizeCategory(string $category): string
    {
        $category = strtolower(trim($category));
        if ('' === $category) {
            $category = 'facts';
        }

        return $category;
    }

    /**
     * The current time as an ISO-8601 UTC timestamp (e.g. 2026-08-27T14:32:05Z).
     * Written to the frontmatter created/updated fields so ordering is exact
     * and portable, rather than a date with no time component.
     */
    private static function nowTimestamp(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    /**
     * Parse a frontmatter created/updated value into a Unix timestamp. Accepts
     * the ISO-8601 UTC timestamps this store writes and the plain "YYYY-MM-DD"
     * dates other tools may write (treated as 00:00 UTC). Returns null when the
     * value is empty or cannot be parsed.
     */
    private static function parseTimestamp(string $value): ?int
    {
        $value = trim($value);
        if ('' === $value) {
            return null;
        }
        if (1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $value .= 'T00:00:00Z';
        }
        $ts = strtotime($value);

        return false === $ts ? null : $ts;
    }

    private function newId(string $title): string
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $title) ?? '') ?: 'memory');
        $slug = trim($slug, '-');
        $slug = substr($slug, 0, 48);
        $slug = $slug !== '' ? $slug : 'memory';

        return $slug . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseDocument(string $category, string $id, string $raw): array
    {
        ['frontmatter' => $fm, 'body' => $body] = Frontmatter::split($raw);

        $tags = $fm['tags'] ?? [];
        if (!is_array($tags)) {
            $tags = [];
        }

        $title = '';
        if (isset($fm['title']) && is_string($fm['title']) && '' !== $fm['title']) {
            $title = $fm['title'];
        } elseif (preg_match('/^#\s+(.+)$/m', $body, $m)) {
            $title = trim($m[1]);
        }

        // The title lives in the frontmatter / UI title field, not in the body.
        // Strip the leading H1 (and any accidental duplicates) so that saving a
        // memory back never re-prepends the title and compounds the heading.
        $body = self::stripLeadingHeadings($body);

        $date = gmdate('Y-m-d');
        $created = isset($fm['created']) && is_string($fm['created']) ? $fm['created'] : $date;
        $updatedRaw = isset($fm['updated']) && is_string($fm['updated']) ? $fm['updated'] : '';
        $updated = '' !== $updatedRaw ? $updatedRaw : $date;

        // Precise last-modified time, used for ordering and the "new" badge.
        // Our own files carry an ISO-8601 UTC timestamp; a file written by
        // another tool may hold only a date (or nothing), so fall back to the
        // file's mtime to keep ordering meaningful.
        $updatedTs = self::parseTimestamp($updatedRaw);
        if (null === $updatedTs) {
            $file = $this->paths->file($category, $id);
            $mtime = null !== $file ? @filemtime($file) : false;
            if (false !== $mtime) {
                $updatedTs = $mtime;
            }
        }

        $path = $category . '/' . $id . '.md';

        return [
            'id' => $id,
            'category' => $category,
            'title' => $title,
            'path' => $path,
            'tags' => array_values(array_map('strval', $tags)),
            'created' => $created,
            'updated' => $updated,
            'updated_ts' => $updatedTs,
            'source' => isset($fm['source']) && is_string($fm['source']) ? $fm['source'] : 'unknown',
            'archived' => filter_var($fm['archived'] ?? false, FILTER_VALIDATE_BOOL),
            'body' => $body,
        ];
    }
}
