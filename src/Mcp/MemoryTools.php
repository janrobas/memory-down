<?php

declare(strict_types=1);

namespace MemoryDown\Mcp;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use MemoryDown\Memory\MemoryStore;
use MemoryDown\Memory\PathValidator;
use MemoryDown\Memory\RecommendedTags;
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

    /** Upper bound on a single memory body, to protect shared hosting. */
    private const MAX_CONTENT_BYTES = 102400;

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
            $this->readMemory(),
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
                . 'of creating a duplicate. Unrelated information is never overwritten. '
                . 'Tags are freeform EXCEPT the reserved tag "executable", which is rejected here: it marks a '
                . 'workflow definition and can only be added by the user in the admin UI, because a workflow '
                . 'that a runner executes must be authorised by a human, not by an agent.',
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
                    'category' => ['type' => 'string', 'description' => 'One of: preferences, projects, decisions, workflows, ideas, references, goals, facts, people, context, notes. Defaults to "facts" (use "notes" for anything that does not clearly fit another category). The "writings" category is read-only through MCP and is rejected. To draft a post, store it in "ideas" or "notes" instead — the user moves it into writings in the admin UI.'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional but recommended: 1-3 short lowercase tags for grouping and search, e.g. ["project-x", "meeting-notes"]. ' . RecommendedTags::summary() . ' The reserved tag "executable" cannot be set here (admin UI only, see docs/WORKFLOWS.md).'],
                    'archived' => ['type' => 'boolean', 'description' => 'Set true to store the memory as archived. Archived memories are kept and remain searchable, but are ranked after active ones. Default false.'],
                ],
                'required' => ['content'],
            ],
            'handler' => function (string $content, string $title = '', string $category = 'facts', array $tags = [], bool $archived = false): CallToolResult {
                $title = trim($title);
                $content = trim($content);
                if ('' === $content) {
                    return self::fail('content must not be empty.');
                }
                if (strlen($content) > self::MAX_CONTENT_BYTES) {
                    return self::fail('content is too large (max ' . (self::MAX_CONTENT_BYTES / 1024) . ' KB).');
                }
                if (!PathValidator::isCategory($category)) {
                    return self::fail('Invalid category. Allowed: preferences, projects, decisions, workflows, ideas, references, goals, facts, people, context, notes.');
                }
                if (PathValidator::isWriteProtectedCategory($category)) {
                    return self::failWriteProtected(
                        $category,
                        'memories cannot be stored in it.',
                        'If a draft is needed, store it in another category (for example ideas or notes) — '
                        . 'the user can move it into writings, and publish it, in the admin UI.',
                    );
                }
                $reserved = PathValidator::humanOnlyTag($tags);
                if ('' !== $reserved) {
                    return self::failHumanOnlyTag($reserved, 'memories cannot be created with it.');
                }
                $tags = array_values(array_filter(array_map('strval', $tags)));

                $duplicate = $this->store->findDuplicate($content, $title, $category);
                if (null !== $duplicate) {
                    // An existing entry carrying the reserved tag is never
                    // rewritten here: what a runner executes is the definition
                    // the user authorised, not a version an agent replaced
                    // underneath the tag.
                    $reserved = PathValidator::humanOnlyTag($duplicate['tags'] ?? []);
                    if ('' !== $reserved) {
                        return self::failHumanOnlyTag($reserved, 'this memory cannot be updated here.');
                    }
                    $changes = [
                        'content' => $content,
                        'title' => $title !== '' ? $title : $duplicate['title'],
                        'tags' => array_values(array_unique(array_merge($duplicate['tags'], $tags))),
                    ];
                    if ($archived) {
                        $changes['archived'] = true;
                    }
                    $updated = $this->store->update($duplicate['category'], $duplicate['id'], $changes);

                    return self::ok([
                        'action' => 'updated',
                        'message' => 'Updated the existing memory that already represented this information.',
                        'memory' => self::publicDocument($updated),
                    ]);
                }

                $doc = $this->store->create($content, $title, $category, $tags, archived: $archived);

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
                . 'recently updated memories are returned; with a query, the most relevant ones. Archived '
                . 'memories are included but ranked after active ones; pass archived="active" to exclude them.',
            'annotations' => new ToolAnnotations(
                readOnlyHint: true,
                openWorldHint: false,
            ),
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Optional words describing the topic you need context on (e.g. a project, person or preference). Omit to get the most recently updated memories. May include "tag:foo" to require a tag.'],
                    'category' => ['type' => 'string', 'description' => 'Restrict to one category (preferences, projects, decisions, workflows, ideas, references, goals, facts, people, context, notes, writings). Entries in "writings" are returned like any other, but that category is read-only through MCP.'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum number of memories to return (default 10, max 50).'],
                    'tag' => ['type' => 'string', 'description' => 'Optional: return only memories carrying this exact tag (case-insensitive).'],
                    'archived' => ['type' => 'string', 'enum' => ['all', 'active', 'archived'], 'description' => 'Which memories to include: "all" (default, active first then archived), "active" only, or "archived" only.'],
                ],
            ],
            'handler' => function (string $query = '', string $category = '', int $limit = self::DEFAULT_LIMIT, string $tag = '', string $archived = 'all'): CallToolResult {
                if (!PathValidator::isCategory($category) && '' !== $category) {
                    return self::fail('Invalid category.');
                }
                $limit = min(max(1, $limit), 50);
                $query = trim($query);
                $archivedFilter = self::archivedFilter($archived);
                $probe = $limit + 1;

                $docs = '' !== $query
                    ? $this->search->search(
                        $query,
                        '' !== $category ? $category : null,
                        $probe,
                        includeBody: true,
                        tag: '' !== $tag ? $tag : null,
                        archived: $archivedFilter,
                    )
                    : $this->store->list(
                        '' !== $category ? $category : null,
                        $probe,
                        $archivedFilter,
                        '' !== $tag ? $tag : null,
                    );

                [$docs, $returned, $hasMore] = self::page($docs, $limit);

                if ([] === $docs) {
                    return self::ok(['memories' => [], 'returned' => 0, 'has_more' => false, 'message' => 'No memories found.']);
                }

                $public = array_map(self::publicDocument(...), $docs);

                return self::ok(['memories' => $public, 'returned' => $returned, 'has_more' => $hasMore]);
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
                . 'explicitly. Matches filenames, titles, frontmatter tags and body text, ranked by relevance. '
                . 'Results never carry the body, only a SHORT SNIPPET of it: call read_memory with the returned '
                . 'id to read an entry in full. '
                . 'Archived memories are included but ranked after active ones; pass archived="active" to '
                . 'exclude them. The query may contain "tag:foo" to require a tag and "is:archived"/"is:active".',
            'annotations' => new ToolAnnotations(
                readOnlyHint: true,
                openWorldHint: false,
            ),
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'What to look up in the user\'s memory, e.g. a preference, favorite thing, past decision, fact about the user, or anything they may have asked you to remember. May include "tag:foo".'],
                    'category' => ['type' => 'string', 'description' => 'Restrict the search to one category (preferences, projects, decisions, workflows, ideas, references, goals, facts, people, context, notes, writings). Entries in "writings" are searchable, but that category is read-only through MCP.'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum results (default 10, max 50).'],
                    'tag' => ['type' => 'string', 'description' => 'Optional: return only memories carrying this exact tag (case-insensitive).'],
                    'archived' => ['type' => 'string', 'enum' => ['all', 'active', 'archived'], 'description' => 'Which memories to include: "all" (default, active first then archived), "active" only, or "archived" only.'],
                ],
                'required' => ['query'],
            ],
            // NOTE: parameter names must mirror the JSON schema keys exactly —
            // the MCP SDK matches arguments by parameter name with no
            // snake_case conversion. A mismatch silently falls back to the
            // default, which is how `include_body` used to be ignored.
            //
            // There is deliberately no `include_body` here: carrying the full
            // body of up to 50 matches in one result is wasteful, and
            // read_memory reads a single entry in full. recall still returns
            // bodies for context loading.
            'handler' => function (string $query, string $category = '', int $limit = self::DEFAULT_LIMIT, string $tag = '', string $archived = 'all'): CallToolResult {
                $query = trim($query);
                if ('' === $query) {
                    return self::fail('query must not be empty.');
                }
                if (!PathValidator::isCategory($category) && '' !== $category) {
                    return self::fail('Invalid category.');
                }
                $limit = min(max(1, $limit), 50);
                $probe = $limit + 1;

                $results = $this->search->search(
                    $query,
                    '' !== $category ? $category : null,
                    $probe,
                    includeBody: false,
                    tag: '' !== $tag ? $tag : null,
                    archived: self::archivedFilter($archived),
                );

                [$results, $returned, $hasMore] = self::page($results, $limit);

                return self::ok(self::searchPayload($results, $returned, $hasMore));
            },
        ];
    }

    private function readMemory(): array
    {
        return [
            'name' => 'read_memory',
            'description' => 'Read ONE memory entry in full: its complete Markdown body plus its metadata '
                . '(title, path, category, tags, created, updated, archived, public). search_memory and '
                . 'list_memory return only a short snippet and metadata, so call this with the id (or the path) '
                . 'they returned whenever you need the actual text — to show an entry to the user, to check it '
                . 'before changing it, or to append to / edit part of it. The whole body is returned by '
                . 'default; pass offset and max_chars to page through a very long entry. Reading is always '
                . 'allowed, including entries in the read-only "writings" category.',
            'annotations' => new ToolAnnotations(
                readOnlyHint: true,
                openWorldHint: false,
            ),
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'description' => 'The id of the entry (e.g. "mentor-od-novembra-2024-96e3d3"), or its path exactly as returned by the other tools (e.g. "facts/mentor-od-novembra-2024-96e3d3.md"). A trailing ".md" is optional.'],
                    'category' => ['type' => 'string', 'description' => 'Optional. Only needed to disambiguate; otherwise the category is taken from the id or path.'],
                    'offset' => ['type' => 'integer', 'description' => 'Optional character offset to start at (default 0). Use with max_chars to page through a long entry.'],
                    'max_chars' => ['type' => 'integer', 'description' => 'Optional maximum number of characters to return (default 0 = everything from offset on, no truncation).'],
                    'include_frontmatter' => ['type' => 'boolean', 'description' => 'Also return the raw frontmatter block as stored on disk (default false; tags, created, updated, archived and public are always returned as structured fields anyway).'],
                ],
                'required' => ['id'],
            ],
            // Parameter names mirror the JSON schema keys exactly (see the note
            // on search_memory: the SDK does no snake_case conversion).
            'handler' => function (string $id, string $category = '', int $offset = 0, int $max_chars = 0, bool $include_frontmatter = false): CallToolResult {
                $reference = MemoryStore::locate($id);
                if (null === $reference) {
                    return self::fail('Invalid memory id or path. Pass the id or path returned by search_memory or list_memory, e.g. "facts/mentor-od-novembra-2024-96e3d3.md".');
                }
                if ('' !== $category && !PathValidator::isCategory($category)) {
                    return self::fail('Invalid category.');
                }

                // Explicit argument, then the category carried by the path, then
                // a scan of the whole corpus (ids are globally unique).
                $resolved = '' !== $category
                    ? $category
                    : ($reference['category'] ?? $this->categoryFor($reference['id']));
                $doc = null !== $resolved ? $this->store->read($resolved, $reference['id']) : null;
                if (null === $doc || null === $resolved) {
                    return self::fail("No memory entry found with id {$reference['id']}.");
                }

                $body = (string) ($doc['body'] ?? '');
                $bodyChars = mb_strlen($body);
                $offset = max(0, $offset);
                $max_chars = max(0, $max_chars);
                $window = 0 === $max_chars ? mb_substr($body, $offset) : mb_substr($body, $offset, $max_chars);
                $returned = mb_strlen($window);
                $hasMore = $offset + $returned < $bodyChars;

                $memory = self::publicDocument($doc);
                $memory['body'] = $window;

                $data = [
                    'memory' => $memory,
                    'body_chars' => $bodyChars,
                    'returned_chars' => $returned,
                    'offset' => $offset,
                    'has_more' => $hasMore,
                    'next_offset' => $hasMore ? $offset + $returned : null,
                    // A partial body must never be written back: doing so would
                    // silently drop everything outside the window.
                    'partial' => $offset > 0 || $hasMore,
                ];
                if (true === $data['partial']) {
                    $data['warning'] = 'This body is PARTIAL (offset ' . $offset . ' of ' . $bodyChars
                        . ' characters). Read it again without offset/max_chars before passing it to '
                        . 'update_memory, or the missing text will be lost.';
                }
                if ($include_frontmatter) {
                    $data['frontmatter'] = self::frontmatterBlock($this->store->raw($resolved, $reference['id']));
                }

                return self::ok($data);
            },
        ];
    }

    private function updateMemory(): array
    {
        return [
            'name' => 'update_memory',
            'description' => 'Update an existing memory entry, identified by its id (returned by remember, '
                . 'recall, search_memory, read_memory or list_memory). Only the provided fields are changed; '
                . 'everything else is preserved. The file keeps its id; changing the category moves the file. '
                . 'Call read_memory first to obtain the current body before rewriting it: content replaces the '
                . 'whole body, so anything you do not send back is lost. Entries in the '
                . '"writings" category are read-only through MCP and cannot be updated or moved, and no entry '
                . 'can be moved into that category. The reserved tag "executable" cannot be added here, and an '
                . 'entry that already carries it (a workflow definition the user authorised) is read-only '
                . 'through MCP: it cannot be edited, archived or moved, only read — ask the user to change it '
                . 'in the admin UI.',
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
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Replacement tag list (short lowercase tags for grouping and search). ' . RecommendedTags::summary() . ' The reserved tag "executable" cannot be set here (admin UI only).'],
                    'category' => ['type' => 'string', 'description' => 'New category; the file is moved there. The "writings" category is read-only through MCP: entries cannot be moved into it.'],
                    'archived' => ['type' => 'boolean', 'description' => 'Set true to archive the entry, false to restore it to active.'],
                ],
                'required' => ['id'],
            ],
            'handler' => function (string $id, string $content = '', string $title = '', array $tags = [], string $category = '', ?bool $archived = null): CallToolResult {
                if (!PathValidator::isId($id)) {
                    return self::fail('Invalid memory id.');
                }
                $reserved = PathValidator::humanOnlyTag($tags);
                if ('' !== $reserved) {
                    return self::failHumanOnlyTag($reserved, 'the tag cannot be added through MCP.');
                }
                $changes = [];
                if ('' !== $content) {
                    if (strlen($content) > self::MAX_CONTENT_BYTES) {
                        return self::fail('content is too large (max ' . (self::MAX_CONTENT_BYTES / 1024) . ' KB).');
                    }
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
                    if (PathValidator::isWriteProtectedCategory($category)) {
                        return self::failWriteProtected($category, 'entries cannot be moved into it.');
                    }
                    $changes['category'] = $category;
                }
                if (null !== $archived) {
                    $changes['archived'] = $archived;
                }
                if ([] === $changes) {
                    return self::fail('Nothing to update: provide at least one of content, title, tags, category or archived.');
                }

                // A read-only category is refused wherever the entry lives, so a
                // writing can never be edited (or archived) through MCP.
                $currentCategory = $this->categoryFor($id);
                if (null === $currentCategory) {
                    return self::fail("No memory entry found with id {$id}.");
                }
                if (PathValidator::isWriteProtectedCategory($currentCategory)) {
                    return self::failWriteProtected($currentCategory, 'this memory cannot be updated here.');
                }
                $current = $this->store->read($currentCategory, $id);
                $reserved = PathValidator::humanOnlyTag($current['tags'] ?? []);
                if ('' !== $reserved) {
                    return self::failHumanOnlyTag($reserved, 'this memory cannot be changed here.');
                }

                try {
                    $doc = $this->store->update($currentCategory, $id, $changes);
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
            'description' => 'Delete a memory entry by its id. '
                . 'It is moved to the trash, where the user can still restore it in the admin UI — but treat it as '
                . 'gone: only call this when the user explicitly asks to remove or forget a memory, and read_memory '
                . 'it first if the target is ambiguous. The entry disappears from memory, search and recall. '
                . 'Entries in the "writings" category are read-only through MCP and cannot be deleted here. '
                . 'An entry carrying the reserved tag "executable" (a workflow definition) cannot be deleted '
                . 'here either — deleting an authorised workflow is the user\'s call in the admin UI.',
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
                    'category' => ['type' => 'string', 'description' => 'The category the entry lives in (used to locate the file). The "writings" category is read-only through MCP.'],
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

                $currentCategory = '' !== $category ? $category : $this->categoryFor($id);
                if (null === $currentCategory) {
                    return self::fail("No memory entry found with id {$id}.");
                }
                if (PathValidator::isWriteProtectedCategory($currentCategory)) {
                    return self::failWriteProtected($currentCategory, 'this memory cannot be deleted here.');
                }
                $current = $this->store->read($currentCategory, $id);
                $reserved = PathValidator::humanOnlyTag($current['tags'] ?? []);
                if ('' !== $reserved) {
                    return self::failHumanOnlyTag($reserved, 'this memory cannot be deleted here.');
                }

                try {
                    $deleted = $this->store->delete($currentCategory, $id);
                } catch (\RuntimeException $e) {
                    // The trash is unwritable: refuse rather than destroy the
                    // entry, and say why so it is not mistaken for a protocol bug.
                    return self::fail($e->getMessage());
                }
                if (!$deleted) {
                    return self::fail("No memory entry found with id {$id}.");
                }

                return self::ok([
                    'action' => 'deleted',
                    'message' => "Memory entry {$id} deleted. It is in the trash and can still be restored in the admin UI.",
                    'id' => $id,
                ]);
            },
        ];
    }

    private function listMemory(): array
    {
        return [
            'name' => 'list_memory',
            'description' => 'List memory entries (metadata only, no full bodies), newest first. '
                . 'Useful for discovering what is stored and obtaining ids for update_memory or forget_memory. '
                . 'Bodies are never returned here: follow up with read_memory to read one entry in full. '
                . 'With no category all categories are listed. Archived memories are included but listed '
                . 'after active ones; pass archived="active" to exclude them.',
            'annotations' => new ToolAnnotations(
                readOnlyHint: true,
                openWorldHint: false,
            ),
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'category' => ['type' => 'string', 'description' => 'Restrict to one category.'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum entries (default 10, max 200).'],
                    'tag' => ['type' => 'string', 'description' => 'Optional: return only memories carrying this exact tag (case-insensitive).'],
                    'archived' => ['type' => 'string', 'enum' => ['all', 'active', 'archived'], 'description' => 'Which memories to include: "all" (default, active first then archived), "active" only, or "archived" only.'],
                ],
            ],
            'handler' => function (string $category = '', int $limit = self::DEFAULT_LIMIT, string $tag = '', string $archived = 'all'): CallToolResult {
                if (!PathValidator::isCategory($category) && '' !== $category) {
                    return self::fail('Invalid category.');
                }
                $limit = min(max(1, $limit), 200);

                $docs = $this->store->list(
                    '' !== $category ? $category : null,
                    $limit,
                    self::archivedFilter($archived),
                    '' !== $tag ? $tag : null,
                );

                return self::ok([
                    'categories' => $this->store->categories(),
                    'total' => $this->store->count(),
                    'returned' => count($docs),
                    'memories' => array_map(self::publicDocument(...), $docs),
                ]);
            },
        ];
    }

    /* ------------------------------------------------------------------ */

    /**
     * The search_memory response shape, built in one place so both branches
     * agree.
     *
     * Search results are metadata plus a short snippet and NEVER a body. That is
     * stated outright in the response — `bodies_included: false` for a client to
     * branch on, and a note naming read_memory for a model that reads the text —
     * so a snippet can never be mistaken for the whole entry.
     *
     * @param list<array<string, mixed>> $results
     *
     * @return array<string, mixed>
     */
    private static function searchPayload(array $results, int $returned, bool $hasMore): array
    {
        $payload = [
            'results' => $results,
            'returned' => $returned,
            'has_more' => $hasMore,
            'bodies_included' => false,
        ];
        if ([] === $results) {
            $payload['message'] = 'No matches.';

            return $payload;
        }

        $payload['note'] = 'These results carry metadata and a short "snippet" only, never the body. '
            . 'Call read_memory with an id (or its path) to read an entry in full.';

        return $payload;
    }

    /**
     * Split a data set into a single page plus a "more available" flag. The
     * caller is expected to have fetched one extra item (limit + 1) so that
     * has_more is exact rather than a guess.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return array{0: list<array<string, mixed>>, 1: int, 2: bool}
     */
    private static function page(array $items, int $limit): array
    {
        $hasMore = count($items) > $limit;
        $page = array_slice($items, 0, max(1, $limit));

        return [$page, count($page), $hasMore];
    }

    /**
     * Locate the category of an id across all categories — including the
     * read-only ones, so an entry in a write-protected category is found (and
     * then refused) rather than being silently defaulted elsewhere. Memory ids
     * carry a random suffix and are globally unique across the whole corpus, so
     * at most one category can contain a given id. Returns null when no
     * category contains the id.
     */
    private function categoryFor(string $id): ?string
    {
        foreach ($this->store->categories() as $category) {
            if (null !== $this->store->read($category, $id)) {
                return $category;
            }
        }

        return null;
    }

    /**
     * Map a tri-state "all|active|archived" selector to a nullable boolean
     * filter (null = all). Unknown values mean "all".
     */
    private static function archivedFilter(string $value): ?bool
    {
        return match (strtolower(trim($value))) {
            'archived' => true,
            'active' => false,
            default => null,
        };
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
        unset($public['source'], $public['updated_ts'], $public['created_ts']);

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

    /**
     * Extract the leading `---` fenced frontmatter block from a raw file,
     * fences included, or null when the file has none. Mirrors the parsing in
     * {@see \MemoryDown\Memory\Frontmatter::split()} (LF and CRLF alike).
     */
    private static function frontmatterBlock(?string $raw): ?string
    {
        if (null === $raw) {
            return null;
        }
        if (1 !== preg_match('/^---\r?\n.*?\r?\n---[ \t]*(?:\r?\n|$)/s', $raw, $m)) {
            return null;
        }

        return rtrim($m[0]);
    }

    /**
     * Refuse a write to a read-only category with an actionable message, so the
     * calling LLM understands why and what to do instead of retrying blindly.
     *
     * $outro may be overridden where a more useful way forward exists than the
     * generic one — notably when an agent tries to *create* something it cannot
     * create, rather than to change something it cannot change.
     */
    private static function failWriteProtected(
        string $category,
        string $detail,
        string $outro = 'The user manages this category in the admin UI.',
    ): CallToolResult {
        return self::fail(
            'The "' . strtolower(trim($category)) . '" category is read-only through MCP: ' . $detail . ' ' . $outro
        );
    }

    /**
     * Refuse an operation involving a reserved, human-only tag.
     *
     * Two different refusals share this message, because both mean the same
     * thing to the calling agent: an agent may not create, edit, move, archive
     * or delete a workflow definition. Granting execution is the user's act,
     * and it is taken in the admin UI — see docs/WORKFLOWS.md.
     */
    private static function failHumanOnlyTag(string $tag, string $detail): CallToolResult
    {
        return self::fail(
            'The "' . $tag . '" tag is reserved: ' . $detail
            . ' It can only be added in the admin UI, and any memory carrying it is read-only through MCP, '
            . 'so a workflow definition cannot be created, rewritten, moved, archived or deleted by an agent. '
            . 'Propose the change to the user and let them make it in the admin UI.'
        );
    }
}
