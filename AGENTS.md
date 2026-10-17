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

The implementation should be appropriate for a small personal application that is
shared by a few people — one instance per household or team, not a hosted
multi-tenant service.

Do not build unnecessary multi-tenant functionality: no organisations, no
self-service sign-up, no per-account quotas or billing.

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
      users/
        alice/           one directory per account; the boundary is the directory
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
            writings/
            .trash/        deleted entries, restorable from the admin UI
          index/
            memory.sqlite  disposable search cache for that account only
      auth/               accounts, OAuth clients and tokens (never web-readable)
      sessions/
      logs/

Example:

    data/users/alice/memory/preferences/notes-system.md

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

The account id is a directory name *and* a login name, so it must be a strict
slug, and no path may ever be built from it without validation.

---

## Memory tools

The initial MCP tool set should be small.

Implement:

- `remember`
- `recall`
- `search_memory`
- `read_memory`
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

### read_memory

Read a single entry in full, by its id or by its `category/id.md` path.

This exists because `search_memory` and `list_memory` return only metadata and a
short snippet, and neither of them can read one specific entry. `recall` does
return bodies, but only as context loading — it has no id lookup. An agent must be
able to obtain the complete, current text before rewriting it with
`update_memory`, otherwise the content it never saw is silently lost.

`search_memory` deliberately has no way to return bodies: carrying the full body
of up to 50 matches in one result is wasteful, and `read_memory` covers the
single-entry case. `recall` remains the one tool that returns bodies by default.

Reading is always allowed, including entries in the write-protected `writings`
category. The body is returned whole by default; `offset`/`max_chars` page
through a very long entry, and a partial body must never be written back.

### update_memory

Update a specific memory safely.

### forget_memory

Delete a specific memory.

Deletion is explicit, but not final: the entry is moved into the write-protected
trash folder (`.trash/`, a dot-prefixed name that can never be a category, so it
is invisible to the corpus, search, the index and every other tool) and the user
can restore it from the admin UI until the trash is emptied. If the trash cannot
be written, the delete must fail with an explanation rather than unlink the file.

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

One exception, added deliberately later: the tag `executable` is **reserved**.
It marks a workflow definition (see `docs/WORKFLOWS.md`), can only be set in the
admin UI, and makes the entry read-only through MCP. It is the only tag with
enforced meaning; everything above still holds for every other tag.

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
- runtime paths (data, accounts, logs, MCP sessions)
- environment
- OAuth configuration
- secrets
- the seed credentials for the very first account only

Accounts, passwords and per-account API tokens are **not** configuration: they
live in `data/auth/users.json` and are managed in the admin UI.

Example:

    APP_BASE_URL=https://memory.example.com
    APP_ENV=production
    DATA_PATH=/absolute/path/to/data

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
- self-service sign-up
- billing
- teams
- organizations
- admin dashboards
- complex permissions
- background jobs
- queues

These can be considered later.

### Amendment: admin UI and search index (in scope)

Three items from the list above have since been explicitly taken into scope,
under the constraints that follow. The rest of the list still stands.

- **An admin UI** (`/ui`) is in scope: browse, search and edit the Markdown
  memory store. It must stay server-rendered PHP with optional light,
  vendored JavaScript — **no frontend framework and no build step** — and remain
  deployable via FTP on shared hosting. It is protected by a username and
  password per account (hash only; never plaintext) and PHP sessions.

- **A full-text search index** is in scope, but **SQLite may only ever be a
  disposable cache**, never canonical storage. Markdown files remain the source
  of truth. The index must be rebuildable from the Markdown at any time, and the
  application must fall back to direct file search when `pdo_sqlite` is absent.
  Deleting the index must lose nothing. It is per account, inside that account's
  directory, so it can never mix two corpora.

- **A read-only public writings API** (`/public/writings`) is in scope, but only
  as a narrowly-scoped publishing surface: it serves entries in the `writings`
  category that carry `public: true`, as Markdown, over a token-gated GET API. It
  must never read or expose anything outside `writings/`, must never write, and
  is disabled by default. Publishing (`public`) is set only in the admin UI, not
  via MCP, so an agent cannot publish on the user's behalf. No rendering, no
  comments: the consuming website owns display. The token is per account and
  decides whose corpus is served.

- **A read-only workflows API** (`/api/workflows`) is in scope on the same terms,
  for the other direction: a runner that cannot read the filesystem fetches the
  definitions over HTTP. `WORKFLOWS_API_ENABLED` (default `false`) gates it, and
  the token is per account (admin UI → Settings), like the writings API. It serves
  the `workflows` category only, as JSON with the stored Markdown body, reports
  the `executable` flag and nothing about whether a definition may run. Only `GET`
  is routed, so there is nothing to write with — it is a second way to *read* the
  same Markdown, never a second source of truth, and reading a definition
  authorises nothing.

- **A recoverable trash and a backup download** are in scope, under the same
  constraint that Markdown stays canonical. A delete moves the file to
  `memory/.trash/` (restorable in the admin UI, purgeable there, never part of
  the corpus) instead of unlinking it, and `/ui/backup` streams a zip of one
  account's memory directory only — never `data/auth/`, `.env` or another
  account, so a download can never leak a credential. Both are session-guarded,
  and neither introduces a scheduler: taking backups is the user's call, on their
  own schedule.

### Amendment: workflow definitions (standard v1, in scope)

Automation definitions are in scope as **data in the memory store**, and nothing
more: `docs/WORKFLOWS.md` is the normative document (in Slovenian).

- A definition lives in the `workflows` category as a normal Markdown entry. Its
  operational configuration (`mode`, `schedule`, `timezone`, `allowed_tools`,
  `llm_provider`, `llm_model`) lives in a fenced `yaml` block **in the body**,
  because the frontmatter is rebuilt from a fixed key list on every save and would
  silently drop it. The block is the first thing in the body; the title is a
  separate field, written as the `# …` heading above it. `mode` is `dry-run`
  (default), `apply` (every mutating action confirmed by the user) or `yolo` (the
  allowlist runs unasked); `allowed_tools` takes a list of ids, a single id, or the
  wildcards `*` and `server.*`.
- The admin UI pre-fills that block (and the instructions skeleton) when a new
  memory is created in `workflows`, from `WorkflowDefinition::TEMPLATE_BODY` — one
  definition of the shape, not a second copy of it in JavaScript. The template is
  deliberately a draft (`TODO`, `<model-id>`), so activating it before filling it
  in is refused by a runner rather than executed. The UI also shows the verdict a
  runner would reach: an advisory parse, never enforcement — a half-written draft
  must stay savable.
- The tag `executable` is reserved and human-only, legal **only in the
  `workflows` category**: the admin UI offers it there and nowhere else, the admin
  API refuses it elsewhere (rather than silently stripping it), and an entry that
  carries it cannot be moved out of the category. Any entry carrying it is
  read-only through MCP (`remember`/`update_memory`/`forget_memory` refuse it).
  This is the trust boundary: an agent may *propose* a workflow, but only the
  user, in the admin UI, can authorise one — and because the definition is then
  unreachable through MCP, an authorised workflow cannot be rewritten underneath
  its own permission. Activation is therefore a human act in the UI; there is no
  separate approval store.
- Nothing executes yet. The scheduler, the LLM runner, the Tool Registry, the
  Telegram integration and the operational SQLite state all belong to the
  future C#/.NET Worker Service, not to MemoryDown; MemoryDown keeps serving
  definitions and memory as Markdown only. No new tables, no queue, no worker
  here.
- `mode: yolo` is in scope on one condition: it is a *request*, and the runner
  executes it only when its own instance-wide switch is on (`AIORCH_ENABLE_YOLO`
  in the runner's environment). MemoryDown neither reads that switch nor stores it
  — it is not one of this application's settings — so an unattended run needs two
  human acts: the mode in the definition (which MCP cannot write) and the switch in
  the runner's environment. `allowed_tools` stays the ceiling in every mode, and the
  runner reports each action it took.
- Still out of scope in this area: embeddings, and any second source of workflow
  definitions.

### Amendment: accounts (in scope)

Multiple accounts are in scope, under the constraints that follow. This replaces
the earlier "one instance per person" position, and nothing else on the
out-of-scope list changes.

- **An account is the unit of isolation, and the filesystem is the boundary.**
  Every account owns `data/users/<id>/` — its memory, its disposable index, its
  MCP transport sessions. No store, index, path or tool call can reach another
  account's directory, because none of them is ever rooted anywhere else. The
  boundary is a directory per account, not an owner column: a missing filter
  cannot leak what is not on the path.
- **One credential per person, in `data/auth/users.json`.** The username and
  password are the same pair for the admin UI (`/ui/login`) and for the OAuth
  consent page, and passwords are stored as one-way hashes. `data/auth/` is never
  web-readable and never part of a backup download.
- **One authorization server per instance.** The consent page identifies the
  account (username + password); the issued token records that account as its
  OAuth `sub`. `/mcp` stays a single endpoint: who signed in decides whose memory
  is served. Protected-resource and authorization-server metadata stay global so
  clients need no per-account discovery.
- **Per-account API tokens** for the two read-only publishing APIs, managed in the
  admin UI. An environment variable cannot name a subject, so
  `PUBLIC_API_TOKEN`/`WORKFLOWS_API_TOKEN` are gone; the `*_API_ENABLED` switch
  stays. Only `data/auth/` holds the values, and the admin UI may show them again
  because the user has to paste them somewhere — the same exposure `.env` had.
- **Credentials are revocable in one integer.** Every account carries an auth
  epoch, bumped on a password change, a role change, a disable or a delete;
  sessions and tokens are stamped with it and refused when it moves. That is what
  makes "this account's connections stopped working" true immediately on shared
  hosting, with no daemon and no sweep of session files.
- **Roles are `admin` and `user`.** Admins manage accounts; users manage their own
  password, tokens and backup. An admin may **download** any account's memory and
  nothing else — reading or editing somebody else's entries in the UI is not
  offered, so the isolation is not undone by a convenience. The last enabled admin
  can be neither demoted, disabled nor deleted, and no one can do it to
  themselves.
- **Deletion is recoverable at every level**: memories go to that account's
  `memory/.trash/`, a deleted account's directory goes to `data/users/.trash/`.
- **Seeding is one-shot.** A pre-accounts instance is migrated on first boot from
  `ADMIN_PASSWORD_HASH`/`OAUTH_CONSENT_PASSWORD`/`OAUTH_USERNAME` and its
  `MEMORY_PATH` corpus; from then on those variables are ignored (a password
  changed in the UI must stick) and the only way to create an account is
  `/ui/setup`, `/ui/users`, or the store itself.
- **Still out of scope here:** self-service sign-up, per-account quotas, and
  anything resembling a hosted multi-tenant service. One instance serves a
  household or a team.

Still explicitly **out of scope**: embeddings/vector search, teams, billing,
background jobs/queues, self-service account creation.

The first goal is:

    ChatGPT
       ↓
     OAuth (consent names the account)
       ↓
      MCP (one endpoint per instance)
       ↓
    remember / search / recall
       ↓
    Markdown files (one directory per account)

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
- accounts exist: an admin creates them, each one owns its own memory directory,
  and nothing reaches across that line
- the same username and password open both the admin UI and the MCP consent page
- a password change, a disable or a delete immediately invalidates that account's
  sessions, MCP tokens and API tokens
- a pre-accounts instance upgrades itself on first boot without losing memory

The project should remain small and maintainable.