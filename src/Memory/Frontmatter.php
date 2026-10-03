<?php

declare(strict_types=1);

namespace MemoryDown\Memory;

/**
 * Minimal YAML-frontmatter reader/writer for Markdown memory files.
 *
 * Only the small subset MemoryDown writes is parsed strictly; anything else
 * in a file's frontmatter is preserved untouched when MemoryDown rewrites a
 * file. Files without frontmatter (created in Obsidian, VS Code, ...) are
 * read as plain Markdown, keeping the whole memory directory portable.
 */
final class Frontmatter
{
    /**
     * @param array<string, mixed> $frontmatter
     */
    public static function render(array $frontmatter): string
    {
        $lines = ['---'];
        foreach ($frontmatter as $key => $value) {
            if (!is_string($key) || !preg_match('/^[a-zA-Z0-9_-]+$/', $key)) {
                continue;
            }
            $rendered = self::renderValue($value);
            $lines[] = str_starts_with($rendered, "\n") ? $key . ':' . $rendered : $key . ': ' . $rendered;
        }
        $lines[] = '---';

        return implode("\n", $lines);
    }

    private static function renderValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            $items = [];
            foreach ($value as $item) {
                $items[] = '  - ' . self::escapeScalar((string) $item);
            }
            if ([] === $items) {
                return '[]';
            }

            return "\n" . implode("\n", $items);
        }

        return self::escapeScalar((string) $value);
    }

    private static function escapeScalar(string $value): string
    {
        if ('' === $value || preg_match('/[:#\[\]\{\},&*!|>\'"%@`]/', $value)) {
            return '"' . str_replace('"', '\\"', $value) . '"';
        }

        return $value;
    }

    /**
     * Split a Markdown document into frontmatter + body.
     *
     * @return array{frontmatter: array<string, mixed>, body: string}
     */
    public static function split(string $raw): array
    {
        if (str_starts_with($raw, "---\n")) {
            $end = strpos($raw, "\n---", 4);
            if (false !== $end) {
                $block = substr($raw, 4, $end - 4);
                $body = substr($raw, $end + 4);
                $body = ltrim($body, "\r\n");

                return ['frontmatter' => self::parse($block), 'body' => $body];
            }
        }

        return ['frontmatter' => [], 'body' => trim($raw, "\r\n")];
    }

    /**
     * @return array<string, mixed>
     */
    public static function parse(string $block): array
    {
        $data = [];
        $lastKey = null;

        foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
            if ('' === trim($line)) {
                continue;
            }

            if (preg_match('/^\s*-\s+(.+)$/', $line, $m)) {
                if (null !== $lastKey) {
                    $data[$lastKey][] = self::unquote(trim($m[1]));
                }
                continue;
            }

            if (preg_match('/^([A-Za-z0-9_-]+)\s*:\s*(.*)$/', $line, $m)) {
                $key = $m[1];
                $value = trim($m[2]);
                if ('' === $value) {
                    // A bare "key:" opens a list whose items follow on "- " lines.
                    $data[$key] = [];
                    $lastKey = $key;
                    continue;
                }
                $data[$key] = self::parseScalar($value);
                $lastKey = is_array($data[$key]) ? $key : null;
            }
        }

        return $data;
    }

    private static function parseScalar(string $value): mixed
    {
        if ('' === $value) {
            return null;
        }
        if ('[]' === $value) {
            return [];
        }
        if (preg_match('/^\[(.*)\]$/', $value, $m)) {
            $items = array_map(static fn (string $i): string => self::unquote(trim($i)), explode(',', $m[1]));
            $items = array_values(array_filter($items, static fn (string $i): bool => '' !== $i));

            return $items;
        }
        if ('true' === strtolower($value)) {
            return true;
        }
        if ('false' === strtolower($value)) {
            return false;
        }

        return self::unquote($value);
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2 && '"' === $value[0] && '"' === substr($value, -1)) {
            return str_replace('\\"', '"', substr($value, 1, -1));
        }
        if (strlen($value) >= 2 && "'" === $value[0] && "'" === substr($value, -1)) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
