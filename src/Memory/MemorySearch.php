<?php

declare(strict_types=1);

namespace MemoryDown\Memory;

/**
 * Simple full-text search over the Markdown corpus (V1: no embeddings, no
 * index). Scores filename, title, tags and body matches; returns ranked
 * documents with a snippet.
 */
final class MemorySearch implements SearchEngine
{
    public function __construct(
        private readonly MemoryStore $store,
        private readonly int $maxResults = 10,
    ) {
    }

    public function rebuild(): void
    {
        // Direct file search has no derived state to rebuild.
    }

    /**
     * @return list<array<string, mixed>> ranked results, each with an added "score" and "snippet"
     */
    public function search(string $query, ?string $category = null, int $limit = 10, bool $includeBody = false, ?string $tag = null, ?bool $archived = null): array
    {
        $limit = min(max(1, $limit), 200);
        $q = SearchQuery::parse($query)->withExplicit($tag, $archived);
        if ($q->isEmpty()) {
            return [];
        }

        $results = [];
        foreach ($this->store->collectFiles($category) as $file) {
            $doc = $this->store->read($this->categoryOf($file), $this->idOf($file));
            if (null === $doc) {
                continue;
            }
            if (null !== $q->archived && (bool) ($doc['archived'] ?? false) !== $q->archived) {
                continue;
            }
            if ([] !== $q->tags && !$this->hasAllTags($doc, $q->tags)) {
                continue;
            }

            $title = strtolower($doc['title'] ?? '');
            $tagText = strtolower(implode(' ', $doc['tags']));
            $idText = strtolower($doc['id']);
            $haystack = $title . ' ' . strtolower((string) $doc['body']) . ' ' . $tagText . ' ' . $idText;

            $score = 0;
            foreach ($q->terms as $needle) {
                if (substr_count($haystack, $needle) > 0) {
                    $score += min(substr_count($haystack, $needle), 5);
                    if (str_contains($title, $needle)) {
                        $score += 3;
                    }
                    if (str_contains($tagText, $needle)) {
                        $score += 2;
                    }
                    if (str_contains($idText, $needle)) {
                        $score += 1;
                    }
                }
            }

            // A tag-only query has no text terms: every tag-matching doc qualifies.
            if ([] !== $q->terms && 0 === $score) {
                continue;
            }

            $result = $doc;
            $result['score'] = $score;
            $result['snippet'] = $this->snippet((string) $doc['body'], $q->terms[0] ?? $query);
            if (!$includeBody) {
                unset($result['body']);
            }
            $results[] = $result;
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
        $docTags = array_map('mb_strtolower', array_map('strval', $doc['tags'] ?? []));
        foreach ($tags as $tag) {
            if (!in_array($tag, $docTags, true)) {
                return false;
            }
        }

        return true;
    }

    private function snippet(string $body, string $query): string
    {
        $body = trim((string) preg_replace('/\s+/', ' ', $body));
        if ('' === $body) {
            return '';
        }

        $pos = mb_stripos($body, $query);
        if (false === $pos || mb_strlen($body) <= 240) {
            return mb_substr($body, 0, 240);
        }

        $start = max(0, $pos - 60);

        return ($start > 0 ? '...' : '') . mb_substr($body, $start, 240) . '...';
    }

    private function categoryOf(string $file): string
    {
        return basename(dirname($file));
    }

    private function idOf(string $file): string
    {
        return substr(basename($file), 0, -3);
    }
}
