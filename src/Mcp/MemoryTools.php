<?php

declare(strict_types=1);

namespace MemoryDown\Mcp;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use MemoryDown\Memory\MemoryStore;
use MemoryDown\Memory\PathValidator;
use MemoryDown\Memory\SearchEngine;

/**
 * The six MCP tools of V1, written for AI agents.
 *
 * Every tool returns a CallToolResult with both a human-readable text block
 * and structuredContent; errors are reported inside the result (isError=true)
 * so the calling LLM can see and self-correct.
 */
final class MemoryTools
{
    private const DEFAULT_LIMIT = 10;

    public function __construct(
        private readonly MemoryStore $store,
        private readonly SearchEngine $search,
    ) {
    }

    /** @return array{handler: callable, name: string, description: string, annotations: ToolAnnotations, inputSchema: array<string, mixed>}[] */
    public function definitions(): array
    {
        return [
            $this->remember(),
            $this->recall(),
            $this->searchMemory(),
            $this->updateMemory(),
            $this->forgetMemory(),
            $this->listMemory(),
        ];
    }

    /* ------------------------------------------------------------------ */

    private function remember(): array
    {
        return [
            'name' => 'remember',
            'description' => 'Store a piece of persistent memory as a Markdown file. '
                . 'Use this for durable information that should survive across conversations: preferences, '
                . 'project context, decisions, useful facts about people or systems, recurring workflows. '
                . 'Do NOT store trivial conversational details. If an existing memory clearly represents the '
                . 'same information (same title or same content in the same category), it is updated instead '
                . 'of creating a duplicate. Unrelated information is never overwritten.',
            'annotations' => new ToolAnnotations(
                readOnlyHint: false,
                destructiveHint: false,
                idempotentHint: true,
                openWorldHint: false,
            ),
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'content' => ['type' => 'string', 'description' => 'The memory content (Markdown allowed).'],
                    'title' => ['type' => 'string', 'description' => 'Short descriptive title, used for the filename and the H1 heading.'],
                    'category' => ['type' => 'string', 'description' => 'One of: preferences, projects, decisions, facts, people, context, notes. Defaults to "facts" (use "notes" for anything that does not clearly fit another category).'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional but recommended: 1-3 short lowercase tags for grouping and search, e.g. ["project-x", "meeting-notes"].'],
                ],
                'required' => ['content'],
            ],
            'handler' => function (string $content, string $title = '', string $category = 'facts', array $tags = []): CallToolResult {
                $title = trim($title);
                $content = trim($content);
                if ('' === $content) {
                    return self::fail('content must not be empty.');
                }
                if (!PathValidator::isCategory($category)) {
                    return self::fail('Invalid category. Allowed: preferences, projects, decisions, facts, people, context, notes.');
                }
                $tags = array_values(array_filter(array_map('strval', $tags)));

                $duplicate = $this->store->findDuplicate($content, $title, $category);
                if (null !== $duplicate) {
                    $updated = $this->store->update($duplicate['category'], $duplicate['id'], [
                        'content' => $content,
                        'title' => $title !== '' ? $title : $duplicate['title'],
                        'tags' => array_merge($duplicate['tags'], $tags),
                    ]);

                    return self::ok([
                        'action' => 'updated',
                        'message' => 'Updated the existing memory that already represented this information.',
                        'memory' => self::publicDocument($updated),
                    ]);
                }

                $doc = $this->store->create($content, $title, $category, $tags);

                return self::ok([
                    'action' => 'created',
                    'message' => 'Memory stored.',
                    'memory' => self::publicDocument($doc),
                ]);
            },
        ];
    }

    private function recall(): array
    {
        return [
            'name' => 'recall',
            'description' => "Load the user's persistent memory into context. Call this proactively at the "
                . 'start of a conversation and whenever the discussion moves to a new topic, so you have the '
                . 'user\'s relevant preferences, projects, decisions, facts and history in mind before '
                . 'answering. You do not need the user to ask about their memory. Without a query the most '
                . 'recently updated memories are returned; with a query, the most relevant ones.',
            'annotations' => new ToolAnnotations(
                readOnlyHint: true,
                openWorldHint: false,
            ),
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Optional words describing the topic you need context on (e.g. a project, person or preference). Omit to get the most recently updated memories.'],
                    'category' => ['type' => 'string', 'description' => 'Restrict to one category (preferences, projects, decisions, facts, people, context, notes).'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum number of memories to return (default 10, max 50).'],
                ],
            ],
            'handler' => function (string $query = '', string $category = '', int $limit = self::DEFAULT_LIMIT): CallToolResult {
                if (!PathValidator::isCategory($category) && '' !== $category) {
                    return self::fail('Invalid category.');
                }
                $limit = min(max(1, $limit), 50);
                $query = trim($query);

                $docs = '' !== $query
                    ? $this->search->search($query, '' !== $category ? $category : null, $limit, includeBody: true)
                    : $this->store->list('' !== $category ? $category : null, $limit);

                if ([] === $docs) {
                    return self::ok(['memories' => [], 'message' => 'No memories found.']);
                }

                $public = array_map(self::publicDocument(...), $docs);

                return self::ok(['memories' => $public]);
            },
        ];
    }

    private function searchMemory(): array
    {
        return [
            'name' => 'search_memory',
            'description' => "Search the user's persistent memory. Use this tool whenever answering "
                . 'questions about the user\'s personal preferences, facts, history, previous decisions, '
                . 'projects, people, or anything the user may have asked to remember. Check it before '
                . 'answering anything personal or past-related, even if the user does not mention memory '
                . 'explicitly. Matches filenames, titles, frontmatter tags and body text, ranked by relevance.',
            'annotations' => new ToolAnnotations(
                readOnlyHint: true,
                openWorldHint: false,
            ),
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'What to look up in the user\'s memory, e.g. a preference, favorite thing, past decision, fact about the user, or anything they may have asked you to remember.'],
                    'category' => ['type' => 'string', 'description' => 'Restrict the search to one category (preferences, projects, decisions, facts, people, context, notes).'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum results (default 10, max 50).'],
                    'include_body' => ['type' => 'boolean', 'description' => 'Include the full body of matching memories (default false; results include a snippet).'],
                ],
                'required' => ['query'],
            ],
            'handler' => function (string $query, string $category = '', int $limit = self::DEFAULT_LIMIT, bool $includeBody = false): CallToolResult {
                $query = trim($query);
                if ('' === $query) {
                    return self::fail('query must not be empty.');
                }
                if (!PathValidator::isCategory($category) && '' !== $category) {
                    return self::fail('Invalid category.');
                }
                $limit = min(max(1, $limit), 50);

                $results = $this->search->search($query, '' !== $category ? $category : null, $limit, $includeBody);
                if ([] === $results) {
                    return self::ok(['results' => [], 'message' => 'No matches.']);
                }

                return self::ok(['results' => $results]);
            },
        ];
    }

    private function updateMemory(): array
    {
        return [
            'name' => 'update_memory',
            'description' => 'Update an existing memory entry, identified by its id (returned by remember, '
                . 'recall, search_memory or list_memory). Only the provided fields are changed; everything else '
                . 'is preserved. The file keeps its id; changing the category moves the file.',
            'annotations' => new ToolAnnotations(
                readOnlyHint: false,
                destructiveHint: false,
                idempotentHint: true,
                openWorldHint: false,
            ),
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'description' => 'The id of the memory entry to update.'],
                    'content' => ['type' => 'string', 'description' => 'New body content (Markdown allowed).'],
                    'title' => ['type' => 'string', 'description' => 'New title.'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Replacement tag list (short lowercase tags for grouping and search).'],
                    'category' => ['type' => 'string', 'description' => 'New category; the file is moved there.'],
                ],
                'required' => ['id'],
            ],
            'handler' => function (string $id, string $content = '', string $title = '', array $tags = [], string $category = ''): CallToolResult {
                if (!PathValidator::isId($id)) {
                    return self::fail('Invalid memory id.');
                }
                $changes = [];
                if ('' !== $content) {
                    $changes['content'] = trim($content);
                }
                if ('' !== $title) {
                    $changes['title'] = trim($title);
                }
                if ([] !== $tags) {
                    $changes['tags'] = array_values(array_filter(array_map('strval', $tags)));
                }
                if ('' !== $category) {
                    if (!PathValidator::isCategory($category)) {
                        return self::fail('Invalid category.');
                    }
                    $changes['category'] = $category;
                }
                if ([] === $changes) {
                    return self::fail('Nothing to update: provide at least one of content, title, tags or category.');
                }

                try {
                    $doc = $this->store->update($this->categoryFor($id), $id, $changes);
                } catch (\InvalidArgumentException $e) {
                    return self::fail($e->getMessage());
                }

                return self::ok([
                    'action' => 'updated',
                    'message' => 'Memory updated.',
                    'memory' => self::publicDocument($doc),
                ]);
            },
        ];
    }

    private function forgetMemory(): array
    {
        return [
            'name' => 'forget_memory',
            'description' => 'Permanently delete a memory entry by its id. '
                . 'DELETION IS DESTRUCTIVE AND CANNOT BE UNDONE — only call this when the user explicitly asks '
                . 'to remove or forget a memory. The corresponding .md file is deleted.',
            'annotations' => new ToolAnnotations(
                readOnlyHint: false,
                destructiveHint: true,
                idempotentHint: true,
                openWorldHint: false,
            ),
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'description' => 'The id of the memory entry to delete.'],
                    'category' => ['type' => 'string', 'description' => 'The category the entry lives in (used to locate the file).'],
                ],
                'required' => ['id'],
            ],
            'handler' => function (string $id, string $category = ''): CallToolResult {
                if (!PathValidator::isId($id)) {
                    return self::fail('Invalid memory id.');
                }
                if ('' !== $category && !PathValidator::isCategory($category)) {
                    return self::fail('Invalid category.');
                }

                $deleted = $this->store->delete('' !== $category ? $category : $this->categoryFor($id), $id);
                if (!$deleted) {
                    return self::fail("No memory entry found with id {$id}.");
                }

                return self::ok(['action' => 'deleted', 'message' => "Memory entry {$id} deleted.", 'id' => $id]);
            },
        ];
    }

    private function listMemory(): array
    {
        return [
            'name' => 'list_memory',
            'description' => 'List memory entries (metadata only, no full bodies), newest first. '
                . 'Useful for discovering what is stored and obtaining ids for update_memory or forget_memory. '
                . 'With no category all categories are listed.',
            'annotations' => new ToolAnnotations(
                readOnlyHint: true,
                openWorldHint: false,
            ),
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'category' => ['type' => 'string', 'description' => 'Restrict to one category.'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum entries (default 10, max 200).'],
                ],
            ],
            'handler' => function (string $category = '', int $limit = self::DEFAULT_LIMIT): CallToolResult {
                if (!PathValidator::isCategory($category) && '' !== $category) {
                    return self::fail('Invalid category.');
                }
                $limit = min(max(1, $limit), 200);

                $docs = $this->store->list('' !== $category ? $category : null, $limit);

                return self::ok([
                    'categories' => $this->store->categories(),
                    'total' => $this->store->count(),
                    'memories' => array_map(self::publicDocument(...), $docs),
                ]);
            },
        ];
    }

    /* ------------------------------------------------------------------ */

    /**
     * Locate the category of an id across all categories (ids are unique).
     */
    private function categoryFor(string $id): string
    {
        foreach ($this->store->categories() as $category) {
            if (null !== $this->store->read($category, $id)) {
                return $category;
            }
        }

        return 'facts';
    }

    /**
     * @param array<string, mixed> $doc
     *
     * @return array<string, mixed>
     */
    private static function publicDocument(array $doc): array
    {
        $public = $doc;
        $public['path'] = $doc['category'] . '/' . $doc['id'] . '.md';
        unset($public['source']);

        return $public;
    }

    private static function ok(mixed $data): CallToolResult
    {
        return new CallToolResult(
            [new TextContent(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))],
            false,
            $data,
        );
    }

    private static function fail(string $message): CallToolResult
    {
        return new CallToolResult([new TextContent($message)], true, ['error' => $message]);
    }
}
