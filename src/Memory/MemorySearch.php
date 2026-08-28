<?php

declare(strict_types=1);

namespace MemoryDown\Memory;

/**
 * Simple full-text search over the Markdown corpus (V1: no embeddings, no
 * index). Scores filename, title, tags and body matches; returns ranked
 * documents with a snippet.
 */
final class MemorySearch
{
    public function __construct(
        private readonly MemoryStore $store,
        private readonly int $maxResults = 10,
    ) {
    }

    /**
     * @return list<array<string, mixed>> ranked results, each with an added "score" and "snippet"
     */
    public function search(string $query, ?string $category = null, int $limit = 10, bool $includeBody = false): array
    {
        $limit = min(max(1, $limit), 50);
        $needles = $this->tokenize($query);
        if ([] === $needles) {
            return [];
        }

        $results = [];
        foreach ($this->store->collectFiles($category) as $file) {
            $doc = $this->store->read($this->categoryOf($file), $this->idOf($file));
            if (null === $doc) {
                continue;
            }

            $score = 0;
            $haystack = strtolower($doc['title'] . ' ' . $doc['body'] . ' ' . implode(' ', $doc['tags']) . ' ' . $doc['id']);
            foreach ($needles as $needle) {
                $count = substr_count($haystack, $needle);
                if ($count > 0) {
                    $score += min($count, 5);
                }
            }

            if ($score > 0) {
                $title = strtolower($doc['title'] ?? '');
                $tagText = strtolower(implode(' ', $doc['tags']));
                $idText = strtolower($doc['id']);
                foreach ($needles as $needle) {
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

                $result = $doc;
                $result['score'] = $score;
                $result['snippet'] = $this->snippet((string) $doc['body'], $query);
                if (!$includeBody) {
                    unset($result['body']);
                }
                $results[] = $result;
            }
        }

        usort($results, static fn (array $a, array $b): int => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        $results = array_slice($results, 0, $limit);

        foreach ($results as $i => $result) {
            $results[$i]['rank'] = $i + 1;
        }

        return $results;
    }

    /**
     * @return list<string> lowercase, deduplicated query tokens (length >= 2)
     */
    private function tokenize(string $query): array
    {
        $tokens = [];
        foreach (preg_split('/\s+/', strtolower(trim($query))) ?: [] as $token) {
            $token = trim($token, "\"'.,;:!?()[]{}");
            if (mb_strlen($token) >= 2) {
                $tokens[] = $token;
            }
        }

        return array_values(array_unique($tokens));
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
