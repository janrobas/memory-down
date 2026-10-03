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

        $today = gmdate('Y-m-d');
        $frontmatter = [
            'type' => $category,
            'tags' => array_values(array_unique(array_filter($tags))),
            'created' => $today,
            'updated' => $today,
            'source' => self::SOURCE,
            'id' => $id,
        ];
        $body = self::bodyWithTitle($title, $content);
        $raw = Frontmatter::render($frontmatter) . "\n\n" . $body . "\n";

        if (false === file_put_contents($file, $raw, LOCK_EX)) {
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
     * @param array{content?: string, title?: string, tags?: string[], category?: string} $changes
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
            'created' => (string) ($current['created'] ?? gmdate('Y-m-d')),
            'updated' => gmdate('Y-m-d'),
            'source' => (string) ($current['source'] ?? self::SOURCE),
            'id' => $id,
        ];
        $raw = Frontmatter::render($frontmatter) . "\n\n" . self::bodyWithTitle($nextTitle, $nextContent) . "\n";

        if ($nextCategory === $category) {
            if (false === file_put_contents($file, $raw, LOCK_EX)) {
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
            if (false === file_put_contents($nextFile, $raw, LOCK_EX)) {
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
     * List entries, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function list(?string $category = null, int $limit = 50): array
    {
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
            if (null !== $doc) {
                $docs[] = $doc;
            }
        }

        usort($docs, static fn (array $a, array $b): int => strcmp((string) ($b['updated'] ?? ''), (string) ($a['updated'] ?? '')));

        return array_slice($docs, 0, max(1, $limit));
    }

    /**
     * @return list<string> absolute paths, newest first
     */
    public function collectFiles(?string $category = null): array
    {
        $root = $this->paths->root();
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.md')) {
                continue;
            }
            $path = $file->getPathname();
            if (null !== $category) {
                $relative = substr($path, strlen($root) + 1);
                if (!str_starts_with($relative, $category . DIRECTORY_SEPARATOR)) {
                    continue;
                }
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
        $updated = isset($fm['updated']) && is_string($fm['updated']) ? $fm['updated'] : $date;

        $path = $category . '/' . $id . '.md';

        return [
            'id' => $id,
            'category' => $category,
            'title' => $title,
            'path' => $path,
            'tags' => array_values(array_map('strval', $tags)),
            'created' => $created,
            'updated' => $updated,
            'source' => isset($fm['source']) && is_string($fm['source']) ? $fm['source'] : 'unknown',
            'body' => $body,
        ];
    }
}
