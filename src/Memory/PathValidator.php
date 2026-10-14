<?php

declare(strict_types=1);

namespace MemoryDown\Memory;

/**
 * Guards every filesystem operation against traversal.
 *
 * Memory paths come from potentially untrusted MCP input. The only names we
 * ever accept are strict slugs (categories) and strict file IDs; both are
 * enforced by whitelist regexes before any filesystem call. As a second line
 * of defence every resolved path is verified to live inside the memory root.
 */
final class PathValidator
{
    /** Allowed category directory names. */
    public const CATEGORY_PATTERN = '/^[a-z0-9][a-z0-9-]{0,63}$/';

    /** Allowed memory entry id: the .md filename without extension. */
    public const ID_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,127}$/';

    /** Built-in categories created at boot. */
    public const DEFAULT_CATEGORIES = [
        'preferences',
        'projects',
        'decisions',
        'workflows',
        'ideas',
        'references',
        'goals',
        'facts',
        'people',
        'context',
        'notes',
        // Reserved for entries a human chooses to publish read-only through the
        // public writings API (see MemoryDown\Publish\Publications).
        'writings',
    ];

    /**
     * Categories an agent may read but never write through MCP: no tool call
     * creates, edits, moves into or deletes anything here. The admin UI still
     * owns them, because publishing a writing is a deliberate human action.
     *
     * @var list<string>
     */
    public const WRITE_PROTECTED_CATEGORIES = ['writings'];

    /**
     * Tags only a human may set, in the admin UI.
     *
     * `executable` marks a memory as a workflow definition that a runner is
     * allowed to execute (see docs/WORKFLOWS.md). It is deliberately NOT
     * settable through MCP: if an agent could add the tag, an agent could
     * authorise its own automation. For the same reason an entry that carries
     * it is read-only through MCP — otherwise the tag the user added could be
     * left in place while the instructions underneath it were rewritten.
     *
     * This is the one deliberate exception to "tags are freeform and never a
     * status"; nothing else is reserved.
     *
     * @var list<string>
     */
    public const HUMAN_ONLY_TAGS = ['executable'];

    /**
     * Where deletions go instead of being unlinked, relative to the memory root.
     *
     * The leading dot is load-bearing: {@see isCategory()} rejects it, so the
     * trash is invisible to collectFiles(), categories(), count(), the index
     * signature, the admin tree, search and the MCP tools for free. A bare
     * "trash" directory would be a perfectly valid category.
     */
    public const TRASH_DIR = '.trash';

    /**
     * Trashed entries are named "{stamp}[_n]__{category}__{id}.md", so the
     * original location is recoverable from the filename alone. The stamp is the
     * deletion time in UTC ("20261011T1830Z") plus an optional "-2" when a name
     * would otherwise collide; the id comes last and is split off with
     * explode('__', $name, 3), which is unambiguous because neither the stamp
     * nor a category can contain an underscore.
     */
    public const TRASH_ENTRY_PATTERN = '/^[0-9]{8}T[0-9]{6}Z(?:-[0-9]{1,3})?__[a-z0-9][a-z0-9-]{0,63}__[a-z0-9][a-z0-9._-]{0,127}\.md$/';

    private string $root;

    public function __construct(string $memoryRoot)
    {
        $this->root = rtrim($memoryRoot, '/\\');
    }

    public function root(): string
    {
        return $this->root;
    }

    public static function isCategory(string $category): bool
    {
        return 1 === preg_match(self::CATEGORY_PATTERN, $category);
    }

    public static function isId(string $id): bool
    {
        return 1 === preg_match(self::ID_PATTERN, $id);
    }

    /**
     * True when the category may be read through MCP but never written to.
     */
    public static function isWriteProtectedCategory(string $category): bool
    {
        return in_array(strtolower(trim($category)), self::WRITE_PROTECTED_CATEGORIES, true);
    }

    /**
     * True when the tag may only be added in the admin UI.
     */
    public static function isHumanOnlyTag(string $tag): bool
    {
        return in_array(strtolower(trim($tag)), self::HUMAN_ONLY_TAGS, true);
    }

    /**
     * The first human-only tag in a list of tags ('' when there is none).
     *
     * Accepts whatever a caller has: the parsed document's "tags" array, a raw
     * value from a tool argument, or anything else. Non-arrays and non-strings
     * simply cannot carry a reserved tag, so they answer ''.
     *
     * @param mixed $tags
     */
    public static function humanOnlyTag(mixed $tags): string
    {
        if (!is_array($tags)) {
            return '';
        }
        foreach ($tags as $tag) {
            // JSON arguments can hold anything; only scalars can spell a tag.
            if (!is_scalar($tag)) {
                continue;
            }
            $tag = strtolower(trim((string) $tag));
            if (self::isHumanOnlyTag($tag)) {
                return $tag;
            }
        }

        return '';
    }

    /**
     * Absolute path for a category directory, or null if invalid.
     */
    public function categoryDir(string $category): ?string
    {
        if (!self::isCategory($category)) {
            return null;
        }

        $path = $this->root . DIRECTORY_SEPARATOR . $category;

        return $this->insideRoot($path) ? $path : null;
    }

    /**
     * Absolute path for a memory file, or null if invalid.
     */
    public function file(string $category, string $id): ?string
    {
        if (!self::isCategory($category) || !self::isId($id)) {
            return null;
        }

        $path = $this->root . DIRECTORY_SEPARATOR . $category . DIRECTORY_SEPARATOR . $id . '.md';

        return $this->insideRoot($path) ? $path : null;
    }

    /**
     * Absolute path for the trash directory, or null if it cannot be resolved.
     */
    public function trashDir(): ?string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . self::TRASH_DIR;

        return $this->insideRoot($path) ? $path : null;
    }

    /**
     * Absolute path for a trashed entry, or null when the name is not a plain,
     * well-formed trash filename. Trash names come from the admin UI, so they
     * get the same treatment as any other path: a strict pattern plus
     * containment, never trust.
     */
    public function trashFile(string $name): ?string
    {
        if (1 !== preg_match(self::TRASH_ENTRY_PATTERN, $name)) {
            return null;
        }

        $dir = $this->trashDir();
        if (null === $dir) {
            return null;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $name;

        return $this->insideRoot($path) ? $path : null;
    }

    /**
     * Verify the resolved real path stays inside the memory root. Handles
     * symlinked or exotic filesystems: resolves the canonical path and checks
     * the prefix. On failure (unreadable parents, missing file) falls back to
     * the lexical check, which the whitelist regexes already make safe.
     */
    private function insideRoot(string $path): bool
    {
        if (str_contains($path, "\0")) {
            return false;
        }

        $realRoot = realpath($this->root);
        if (false !== $realRoot) {
            $realPath = realpath($path);
            if (false !== $realPath) {
                $rootPrefix = rtrim(str_replace('\\', '/', $realRoot), '/') . '/';
                $norm = str_replace('\\', '/', $realPath);

                return str_starts_with($norm, $rootPrefix);
            }
        }

        $normRoot = rtrim(str_replace('\\', '/', $this->root), '/') . '/';
        $normPath = str_replace('\\', '/', $path);

        return str_starts_with($normPath, $normRoot) && !str_contains($normPath, '/../');
    }
}
