<?php

declare(strict_types=1);

namespace MemoryDown\Memory;

/**
 * Simple full-text search over the Markdown corpus (V1: no embeddings, no
 * index). Scores filename, title, tags and body matches; returns ranked
 * documents with a snippet.
 *
 * Matching folds diacritics through {@see Text::fold()}, so "pošta" and
 * "posta" are the same word here — the same answer the SQLite index gives,
 * which matters when this class is the fallback rather than the engine.
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

            $title = Text::fold((string) ($doc['title'] ?? ''));
            $tagText = Text::fold(implode(' ', $doc['tags']));
            $idText = Text::fold($doc['id']);
            $haystack = $title . ' ' . Text::fold((string) $doc['body']) . ' ' . $tagText . ' ' . $idText;

            $score = 0;
            foreach ($q->terms as $term) {
                $needle = Text::fold($term);
                if ('' === $needle) {
                    continue;
                }
                $hits = substr_count($haystack, $needle);
                if ($hits > 0) {
                    $score += min($hits, 5);
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
            $result['snippet'] = $this->snippet((string) $doc['body'], $q->terms, $query);
            if (!$includeBody) {
                // The body is elided, so its size is the one fact a caller still
                // needs: it decides whether reading the entry is cheap.
                $result['chars'] = mb_strlen((string) $doc['body']);
                unset($result['body']);
            }
            $results[] = $result;
        }

        // Same order MemoryStore::list() uses: active first, then best match,
        // then newest. Without the timestamp an older and a newer version of the
        // same text would come back in filesystem order.
        usort($results, static fn (array $a, array $b): int => ((int) ($a['archived'] ?? false) <=> (int) ($b['archived'] ?? false))
            ?: (($b['score'] ?? 0) <=> ($a['score'] ?? 0))
            ?: ((int) ($b['updated_ts'] ?? 0) <=> (int) ($a['updated_ts'] ?? 0)));
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

    /**
     * A short excerpt around the first query term the body really contains.
     *
     * The excerpt always comes out of the ORIGINAL body, so it shows the
     * author's spelling ("pošta", not "posta"). Only the offset may be taken
     * from the folded copy: {@see Text::fold()} maps one character to one
     * character, so a position in the folded text is a valid character offset
     * in the original.
     *
     * Falling back to the first term of the query regardless of whether it
     * occurs would hand back the opening of the entry, which tells a caller
     * nothing about why it matched.
     *
     * @param list<string> $terms
     */
    private function snippet(string $body, array $terms, string $fallback = ''): string
    {
        $body = trim((string) preg_replace('/\s+/', ' ', $body));
        if ('' === $body) {
            return '';
        }

        $candidates = [] !== $terms ? $terms : ('' !== $fallback ? [$fallback] : []);
        $folded = Text::fold($body);
        $pos = false;
        foreach ($candidates as $term) {
            if ('' === $term) {
                continue;
            }
            $pos = mb_stripos($body, $term);
            if (false === $pos) {
                $pos = mb_strpos($folded, Text::fold($term));
            }
            if (false !== $pos) {
                break;
            }
        }

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
