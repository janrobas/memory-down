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

        $trash = $this->paths->trashDir();
        if (null !== $trash && !is_dir($trash)) {
            @mkdir($trash, 0775, true);
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
        bool $public = false,
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
        if ($public) {
            $frontmatter['public'] = true;
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
     * Resolve a loose reference into a validated (category, id) pair.
     *
     * Accepts what the MCP tools hand out, so a caller can copy a value
     * verbatim instead of guessing the shape:
     *
     *   foo-96e3d3                 id only
     *   foo-96e3d3.md              id, extension ignored
     *   facts/foo-96e3d3           category + id
     *   facts/foo-96e3d3.md        category + id, extension ignored
     *
     * Purely syntactic — nothing is read from disk — and every part goes
     * through the same whitelist as any other path. The category is null when
     * the reference did not carry one; the caller then has to find the id
     * across the corpus. Returns null for anything that cannot name a memory
     * file: absolute paths, drive letters, "..", backslashes, extra segments,
     * empty values.
     *
     * @return array{category: ?string, id: string}|null
     */
    public static function locate(string $reference): ?array
    {
        $reference = trim($reference);
        if ('' === $reference || str_contains($reference, "\0") || str_contains($reference, '\\')) {
            return null;
        }
        // An absolute path (POSIX or a Windows drive) never names a memory file.
        if (str_starts_with($reference, '/') || 1 === preg_match('/^[A-Za-z]:/', $reference)) {
            return null;
        }

        $parts = explode('/', $reference);
        if (count($parts) > 2) {
            return null;
        }

        $id = (string) array_pop($parts);
        $category = [] === $parts ? null : $parts[0];

        // A trailing ".md" is the file extension, never part of an id.
        if (str_ends_with(strtolower($id), '.md')) {
            $id = substr($id, 0, -3);
        }

        if (null !== $category && !PathValidator::isCategory($category)) {
            return null;
        }
        if (!PathValidator::isId($id)) {
            return null;
        }

        return ['category' => $category, 'id' => $id];
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
     * The raw text of a memory file, exactly as stored. Unlike {@see read()}
     * nothing is parsed or normalised, so the frontmatter block can be shown as
     * it exists on disk. Returns null when the file does not exist.
     */
    public function raw(string $category, string $id): ?string
    {
        $file = $this->paths->file(self::normalizeCategory($category), $id);
        if (null === $file || !is_file($file)) {
            return null;
        }

        $raw = file_get_contents($file);

        return false === $raw ? null : $raw;
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
        // Publishing is preserved unless the caller explicitly changes it, so an
        // agent edit (which never sends `public`) cannot unpublish a writing.
        $nextPublic = array_key_exists('public', $changes)
            ? (bool) $changes['public']
            : (bool) ($current['public'] ?? false);

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
        if ($nextPublic) {
            $frontmatter['public'] = true;
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

        $target = $this->trashTarget($category, $id);

        if (!@rename($file, $target)) {
            // Never fall back to unlinking: the whole point of the trash is that
            // a delete stays reversible, so a broken trash must fail loudly
            // rather than destroy the entry.
            throw new \RuntimeException(
                'Could not move the entry to the trash directory (' . self::TRASH_DIR . '): '
                . 'check that ' . $this->paths->root() . ' is writable. Nothing was deleted.'
            );
        }

        $this->logger->info('memory.trashed', ['id' => $id, 'category' => $category]);

        return true;
    }

    /**
     * Entries currently in the trash, newest first. Names that do not look like
     * trash entries (a stray file dropped in the folder) are ignored rather than
     * guessed at.
     *
     * @return list<array{name: string, category: string, id: string, deleted_at: string, bytes: int}>
     */
    public function trashed(): array
    {
        $dir = $this->paths->trashDir();
        if (null === $dir || !is_dir($dir)) {
            return [];
        }

        $entries = [];
        foreach (scandir($dir) ?: [] as $name) {
            $file = $this->paths->trashFile($name);
            if (null === $file || !is_file($file)) {
                continue;
            }
            [$category, $id] = self::parseTrashName($name);
            if (null === $category || null === $id) {
                continue;
            }
            $stamp = substr($name, 0, (int) strpos($name, '__'));
            $when = \DateTimeImmutable::createFromFormat('Ymd\THis\Z', $stamp, new \DateTimeZone('UTC'));

            $entries[] = [
                'name' => $name,
                'category' => $category,
                'id' => $id,
                'deleted_at' => false !== $when ? $when->format('Y-m-d\TH:i:s\Z') : gmdate('Y-m-d\TH:i:s\Z', (int) @filemtime($file)),
                'bytes' => (int) @filesize($file),
            ];
        }

        usort($entries, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));

        return $entries;
    }

    /**
     * How many entries the trash holds.
     *
     * Cheaper than counting trashed(): the menu badge asks for this on every
     * page load, so it does one directory read and skips the per-entry work
     * (canonical-path checks, sizes, timestamps) that only the trash page needs.
     * Exact all the same — both filter on the same pattern and file test.
     */
    public function trashCount(): int
    {
        $dir = $this->paths->trashDir();
        if (null === $dir || !is_dir($dir)) {
            return 0;
        }

        $count = 0;
        foreach (scandir($dir) ?: [] as $name) {
            if (1 === preg_match(PathValidator::TRASH_ENTRY_PATTERN, $name)
                && is_file($dir . DIRECTORY_SEPARATOR . $name)
            ) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Put a trashed entry back where it came from. Refuses to overwrite a live
     * entry of the same id, so a restore can never destroy newer content.
     *
     * @return string the restored "category/id"
     */
    public function restore(string $name): string
    {
        $file = $this->paths->trashFile($name);
        if (null === $file || !is_file($file)) {
            throw new \InvalidArgumentException('That entry is no longer in the trash.');
        }

        [$category, $id] = self::parseTrashName($name);
        if (null === $category || null === $id) {
            throw new \InvalidArgumentException('That is not a trashed memory entry.');
        }

        $target = $this->paths->file($category, $id);
        if (null === $target) {
            throw new \InvalidArgumentException('That entry cannot be restored to a valid location.');
        }
        if (is_file($target)) {
            throw new \InvalidArgumentException(
                "Cannot restore: {$category}/{$id}.md exists again. Rename or delete it first."
            );
        }

        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot recreate the category directory for ' . $category . '.');
        }
        if (!@rename($file, $target)) {
            throw new \RuntimeException('Could not restore the entry.');
        }

        $this->logger->info('memory.restored', ['id' => $id, 'category' => $category]);

        return $category . '/' . $id;
    }

    public function purge(string $name): bool
    {
        $file = $this->paths->trashFile($name);
        if (null === $file || !is_file($file)) {
            return false;
        }

        $ok = @unlink($file);
        if ($ok) {
            $this->logger->info('memory.purged', ['entry' => $name]);
        }

        return $ok;
    }

    /**
     * Empty the trash. This is the only operation that really destroys data.
     *
     * @return int number of entries removed
     */
    public function purgeTrash(): int
    {
        $removed = 0;
        foreach ($this->trashed() as $entry) {
            if ($this->purge($entry['name'])) {
                ++$removed;
            }
        }

        if ($removed > 0) {
            $this->logger->info('memory.trash_emptied', ['entries' => $removed]);
        }

        return $removed;
    }

    /**
     * Where a deletion for this entry should land. Adds "-2", "-3", ... to the
     * timestamp when a previous trash entry still holds that name, so a second
     * deletion can never overwrite an earlier copy.
     */
    private function trashTarget(string $category, string $id): string
    {
        $dir = $this->paths->trashDir();
        if (null === $dir || (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir))) {
            throw new \RuntimeException(
                'Cannot create the trash directory (' . self::TRASH_DIR . ') inside ' . $this->paths->root() . '.'
            );
        }

        $stamp = gmdate('Ymd\THis\Z');
        $category = self::normalizeCategory($category);
        $candidate = $stamp . '__' . $category . '__' . $id . '.md';
        for ($n = 2; is_file($dir . DIRECTORY_SEPARATOR . $candidate); ++$n) {
            $candidate = $stamp . '-' . $n . '__' . $category . '__' . $id . '.md';
        }

        return $dir . DIRECTORY_SEPARATOR . $candidate;
    }

    /**
     * Split a trash filename back into its original category and id, or
     * [null, null] when the name is not one of ours.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function parseTrashName(string $name): array
    {
        $parts = explode('__', $name, 3);
        if (3 !== count($parts) || !str_ends_with($parts[2], '.md')) {
            return [null, null];
        }

        $category = $parts[1];
        $id = substr($parts[2], 0, -3);
        if (!PathValidator::isCategory($category) || !PathValidator::isId($id)) {
            return [null, null];
        }

        return [$category, $id];
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
    public function list(?string $category = null, int $limit = 50, ?bool $archived = null, ?string $tag = null, ?bool $public = null): array
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
            if (null !== $public && (bool) ($doc['public'] ?? false) !== $public) {
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
        // drop any leading H1s (including duplicates and the bare "#" older
        // versions wrote for an untitled entry) so exactly one title heading is
        // ever written.
        $content = self::stripLeadingHeadings($content, $adopted);
        if ('' === $title && null !== $adopted && '' !== $adopted) {
            $heading = '# ' . $adopted;
        }
        if ('' !== $heading) {
            return $content !== '' ? $heading . "\n\n" . $content : $heading;
        }

        return $content;
    }

    /**
     * Remove all leading H1 (`# ...`) lines, returning the remaining body.
     * The first one that actually has text is exposed via $first (used as a
     * fallback title).
     *
     * A bare "#" line is a heading with no title: it is stripped like any other
     * leading heading but never becomes the title. It used to be written for
     * untitled entries and, because the title pattern below allowed whitespace —
     * newlines included — to run past it, the *next* line was adopted as the
     * title instead. Horizontal whitespace only, so a heading can never reach
     * into the line after it.
     */
    private static function stripLeadingHeadings(string $text, ?string &$first = null): string
    {
        $text = ltrim($text);
        // "# Title", and a bare "#" (an empty heading, which older versions wrote
        // for an untitled entry). "#hashtag" is not a heading — Markdown needs the
        // space — so a note that starts with one keeps its line.
        while ('' !== $text && 1 === preg_match('/^#(?:[ \t]+(.*?))?[ \t]*(?:\r?\n|$)/', $text, $m)) {
            $heading = $m[1] ?? '';
            if (null === $first && '' !== $heading) {
                $first = $heading;
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
        }

        // The title lives in the frontmatter / UI title field, not in the body:
        // the canonical layout is a leading H1, so strip one (and any accidental
        // duplicates) and take it as the fallback title. Only a *leading* heading
        // counts — a "#" line further down is a YAML comment inside the
        // configuration block or a heading in the instructions, never the title.
        $body = self::stripLeadingHeadings($body, $leading);
        if ('' === $title && null !== $leading) {
            $title = $leading;
        }

        $date = gmdate('Y-m-d');
        $createdRaw = isset($fm['created']) && is_string($fm['created']) ? $fm['created'] : '';
        $created = '' !== $createdRaw ? $createdRaw : $date;
        $updatedRaw = isset($fm['updated']) && is_string($fm['updated']) ? $fm['updated'] : '';
        $updated = '' !== $updatedRaw ? $updatedRaw : $date;

        // Precise timestamps, used for ordering (and the "new" badge). Our own
        // files carry an ISO-8601 UTC timestamp; a file written by another tool
        // may hold only a date (or nothing), so fall back to the file's mtime to
        // keep ordering meaningful.
        $updatedTs = self::parseTimestamp($updatedRaw);
        if (null === $updatedTs) {
            $file = $this->paths->file($category, $id);
            $mtime = null !== $file ? @filemtime($file) : false;
            if (false !== $mtime) {
                $updatedTs = $mtime;
            }
        }
        $createdTs = self::parseTimestamp($createdRaw);
        if (null === $createdTs) {
            $createdTs = $updatedTs;
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
            'created_ts' => $createdTs,
            'updated_ts' => $updatedTs,
            'source' => isset($fm['source']) && is_string($fm['source']) ? $fm['source'] : 'unknown',
            'archived' => filter_var($fm['archived'] ?? false, FILTER_VALIDATE_BOOL),
            'public' => filter_var($fm['public'] ?? false, FILTER_VALIDATE_BOOL),
            'body' => $body,
        ];
    }
}
