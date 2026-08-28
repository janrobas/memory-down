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
        'facts',
        'people',
        'context',
    ];

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
