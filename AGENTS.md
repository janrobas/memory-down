# AGENTS.md

## Project: MemoryDown

MemoryDown is a small personal AI memory server.

Its purpose is to provide persistent memory to AI agents through MCP.

The core architecture is:

    ChatGPT / OpenCode / other AI agents
                    ↓
                MCP over HTTPS
                    ↓
                  OAuth
                    ↓
                MemoryDown
                    ↓
              Markdown files

The project is intentionally small and is designed to run on cheap PHP shared hosting.

---

## Core principles

### Markdown is the source of truth

All persistent memory MUST be stored as Markdown (`.md`) files.

The Markdown files are the canonical data store.

Do not introduce a database for storing the actual memory.

Do not store the canonical memory in:

- SQLite
- MySQL
- PostgreSQL
- JSON
- a vector database
- an external SaaS

Indexes or caches may be introduced in the future if there is a demonstrated need, but they must never replace the Markdown files as the source of truth.

The entire memory directory should remain portable and readable in Obsidian, VS Code, Codex, or any normal text editor.

---

## Hosting constraints

MemoryDown MUST work on ordinary cheap PHP shared hosting.

Assume:

- PHP 8.2+ / preferably PHP 8.3+
- HTTPS
- normal HTTP request/response execution
- no VPS
- no Docker
- no Node server
- no Python runtime
- no Redis
- no PostgreSQL
- no MySQL requirement
- no background workers
- no long-running processes
- potentially no SSH access
- deployment may happen through FTP

Composer is allowed.

Small, focused Composer dependencies are allowed.

Do not introduce a framework.

Do not introduce infrastructure that requires a VPS.

---

## Framework policy

Do NOT use:

- Laravel
- Symfony
- WordPress
- full-stack frameworks
- frontend frameworks unless explicitly requested
- unnecessary abstraction layers

The application should remain understandable as a small PHP application.

Prefer PHP standard-library functionality where practical.

For security-sensitive or protocol-heavy functionality such as MCP and OAuth, prefer a small, established, standards-compliant library over implementing complex protocols incorrectly.

---

## MCP

MemoryDown exposes a remote MCP server over HTTPS.

The primary MCP endpoint should be:

    /mcp

The implementation should use the current official PHP MCP SDK when practical.

Do not manually implement the MCP protocol unless there is a compelling technical reason.

The implementation must work with ordinary PHP shared hosting.

Avoid assumptions that require a persistent daemon or long-running process.

Use the current MCP specification and current SDK documentation.

Do not rely on old MCP tutorials.

---

## ChatGPT compatibility

ChatGPT compatibility is a primary requirement.

Do not assume generic MCP documentation exactly describes ChatGPT's current connection behavior.

Before implementing OAuth/MCP integration, research the current:

- MCP specification
- MCP authorization specification
- Streamable HTTP behavior
- OAuth requirements
- OpenAI/ChatGPT custom MCP requirements

Verify actual behavior where possible.

Pay particular attention to:

- base URL vs `/mcp`
- OAuth discovery
- Protected Resource Metadata
- Authorization Server Metadata
- `WWW-Authenticate`
- PKCE
- MCP `initialize`
- `tools/list`

If ChatGPT may probe the origin/base URL, make the application behave correctly there rather than assuming only `/mcp` will ever be accessed.

---

## OAuth

OAuth is required.

The goal is to allow ChatGPT and other MCP clients to authenticate to MemoryDown securely.

Do not invent a custom authentication protocol.

Use the current MCP-compatible OAuth architecture.

Before implementation, verify the current requirements for:

- OAuth 2.1
- PKCE
- Protected Resource Metadata
- Authorization Server Metadata
- RFC 9728
- bearer authentication
- authorization server discovery
- token validation

The implementation should be appropriate for a single-user personal application.

Do not build unnecessary multi-tenant functionality.

Never commit secrets.

Never log:

- passwords
- access tokens
- refresh tokens
- client secrets
- authorization codes

---

## Important OAuth/MCP debugging lessons

A previous PHP MCP project had several ChatGPT OAuth integration problems.

Important lessons:

### 1. Do not assume the URL ChatGPT uses

ChatGPT may use or probe the base/origin URL even when `/mcp` is supplied.

The application should be tested with both:

    /
    /mcp

where relevant.

### 2. Unauthenticated MCP access must produce the correct challenge

An unauthenticated request should return an appropriate:

    HTTP 401 Unauthorized

with the correct:

    WWW-Authenticate: Bearer ...

challenge and protected-resource metadata reference where required.

### 3. Discovery must be independently testable

Verify:

    protected resource metadata
            ↓
    authorization server metadata
            ↓
    OAuth authorization
            ↓
    token
            ↓
    MCP initialize
            ↓
    tools/list

Do not assume that because one stage works, the rest works.

### 4. Configuration errors can look like protocol errors

A previous calendar integration ultimately had a simple incorrect upstream username.

Always verify configuration, URLs, credentials, and deployment state before rewriting working protocol code.

### 5. "Error refreshing actions" is not a useful diagnosis

If ChatGPT reports something generic such as:

    Error refreshing actions.
    Something went wrong.

Determine which actual stage is failing:

- OAuth
- token validation
- MCP initialization
- session handling
- `tools/list`
- tool schema
- tool execution

Use HTTP tests and server logs rather than guessing.

---

## Storage layout

The memory directory should be simple and human-readable.

Example:

    data/
      memory/
        preferences/
        projects/
        decisions/
        workflows/
        ideas/
        references/
        goals/
        facts/
        people/
        context/
        notes/

Example:

    data/memory/preferences/notes-system.md

Example content:

    ---
    type: preference
    tags:
      - notes
      - markdown
    created: 2026-08-27T09:15:00Z
    updated: 2026-08-27T14:32:05Z
    source: chatgpt
    ---

    # Notes system

    User prefers Markdown-based notes.

The frontmatter format should remain intentionally simple.

Do not create a complicated schema.

---

## Memory tools

The initial MCP tool set should be small.

Implement:

- `remember`
- `recall`
- `search_memory`
- `update_memory`
- `forget_memory`
- `list_memory`

### remember

Create or update persistent memory.

The operation should avoid obvious duplicate memories.

If an existing memory clearly represents the same information, prefer updating it rather than creating an unnecessary duplicate.

Do not silently overwrite unrelated information.

### recall

Retrieve relevant memories.

Return useful information rather than dumping the entire memory store.

### search_memory

Search the Markdown memory corpus.

Initial search should be simple full-text search.

Search should consider:

- filename
- title
- frontmatter
- tags
- body

Do not introduce embeddings or vector databases in V1.

### update_memory

Update a specific memory safely.

### forget_memory

Delete a specific memory.

Deletion is destructive and should be explicit.

### list_memory

List available memory entries or categories.

Do not expose arbitrary filesystem information.

---

## Memory semantics

MemoryDown is not a notes application.

It is a persistent memory layer for AI agents.

The AI should be able to store information that is useful across conversations and agents, such as:

- durable preferences
- project context
- decisions
- recurring workflows
- useful facts
- long-term context

It should not automatically store every conversational detail.

The MCP tool descriptions should clearly communicate appropriate memory behavior to AI agents.

### Recommended tags (optional)

Tags remain freeform. To make grouping and search easier, these optional tags are
recommended to users (admin UI chips) and to AI agents (MCP instructions and the
`remember`/`update_memory` tool descriptions):

- `task` — an actionable item or thing to do
- `followup` — something to revisit or check back on
- `question` — an open question to answer
- `snippet` — a reusable code, config or command fragment

They are a convention, not a schema: nothing enforces them, they are never a
status, and custom tags are always allowed.

---

## Search architecture

Start simple.

The initial implementation should search Markdown files directly.

Do not optimize prematurely.

If the memory corpus eventually becomes large enough that direct search is insufficient, an index may be introduced later.

If an index is introduced:

- Markdown remains canonical
- the index is disposable
- the application must be able to rebuild it
- the index must never become the only copy of memory

---

## Filesystem security

All memory paths come from potentially untrusted MCP input.

Prevent:

- `../` traversal
- encoded traversal
- absolute paths
- access outside the configured memory directory
- arbitrary file deletion
- arbitrary file inclusion

Resolve and validate paths safely.

The MCP server must never expose the rest of the hosting account filesystem.

---

## Diagnostics

Because the application runs on shared hosting, diagnostics are important.

Provide lightweight endpoints such as:

    /health
    /health/mcp
    /health/oauth

Exact endpoints may differ.

Diagnostics should help distinguish:

- PHP/runtime problems
- filesystem permissions
- OAuth discovery
- OAuth authentication
- token validation
- MCP transport
- MCP initialization
- `tools/list`
- memory storage
- search

Never expose secrets through diagnostics.

---

## Logging

Useful server-side logging is encouraged.

Log enough information to diagnose protocol problems.

Never log:

- passwords
- access tokens
- refresh tokens
- OAuth client secrets
- authorization codes

When possible, include a request/correlation ID.

---

## Architecture

Keep the layers separate:

    HTTP / MCP
          ↓
       OAuth
          ↓
    Memory service
          ↓
    Markdown storage

For example:

    MemoryStore
    MemorySearch

should not depend directly on MCP request handling.

The memory layer should be usable independently of the MCP transport.

---

## Configuration

Deployment-specific configuration must not be hard-coded.

Use configuration/environment variables for:

- application base URL
- memory path
- environment
- OAuth configuration
- secrets

Example:

    APP_BASE_URL=https://memory.example.com
    APP_ENV=production
    MEMORY_PATH=/absolute/path/to/data/memory

Never commit production secrets.

---

## Deployment

The application must be deployable through FTP.

Expected deployment:

1. Install Composer dependencies locally.
2. Build the production application.
3. Upload files through FTP.
4. Point the domain/subdomain at `public_html/`.
5. Configure writable data directories.
6. Configure secrets.
7. Test `/health`.
8. Test OAuth discovery.
9. Test MCP.
10. Connect ChatGPT.

Do not assume SSH access exists.

Document the complete deployment procedure in `README.md`.

---

## Testing

For MCP/OAuth changes, test the complete chain:

    HTTPS
      ↓
    health
      ↓
    protected-resource metadata
      ↓
    authorization-server metadata
      ↓
    unauthenticated MCP request
      ↓
    401 + WWW-Authenticate
      ↓
    OAuth authorization
      ↓
    token
      ↓
    MCP initialize
      ↓
    tools/list
      ↓
    tool call
      ↓
    Markdown file

Do not declare the project working merely because `/health` returns OK.

---

## Development workflow

Before implementing significant functionality:

1. Inspect the existing code.
2. Research current MCP/OAuth requirements if relevant.
3. Make an architecture decision.
4. Implement the smallest solution.
5. Test it.
6. Document important decisions.

Do not rewrite working components unnecessarily.

---

## No premature features

Do NOT add these to V1:

- embeddings
- vector databases
- AI summarization pipelines
- automatic wiki generation
- frontend
- mobile app
- multi-user accounts
- billing
- teams
- organizations
- admin dashboards
- complex permissions
- background jobs
- queues

These can be considered later.

### Amendment: admin UI and search index (in scope)

Two items from the list above have since been explicitly taken into scope, under
the constraints that follow. The rest of the list still stands.

- **A single-user admin UI** (`/ui`) is in scope: browse, search and edit the
  Markdown memory store. It must stay server-rendered PHP with optional light,
  vendored JavaScript — **no frontend framework and no build step** — and remain
  deployable via FTP on shared hosting. It is protected by a single admin
  password (hash only; never plaintext) and PHP sessions.

- **A full-text search index** is in scope, but **SQLite may only ever be a
  disposable cache**, never canonical storage. Markdown files remain the source
  of truth. The index must be rebuildable from the Markdown at any time, and the
  application must fall back to direct file search when `pdo_sqlite` is absent.
  Deleting the index must lose nothing.

Still explicitly **out of scope**: embeddings/vector search, multi-user accounts
(one instance per person instead), teams, billing, background jobs/queues.

The first goal is:

    ChatGPT
       ↓
     OAuth
       ↓
      MCP
       ↓
    remember / search / recall
       ↓
    Markdown files

---

## Definition of done

V1 is complete when:

- it runs on cheap PHP shared hosting
- it requires no VPS
- it requires no database
- memory is stored as Markdown
- MCP is reachable over HTTPS
- OAuth works with a current MCP-compatible client
- ChatGPT can authenticate
- ChatGPT can discover the tools
- `initialize` works
- `tools/list` works
- `remember` works
- `recall` works
- `search_memory` works
- `update_memory` works
- `forget_memory` works
- path traversal is prevented
- secrets are not exposed
- deployment is documented

The project should remain small and maintainable.