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

### The filename follows the title (admin UI only)

Renaming an entry in the admin UI moves the file to the new title's slug and
rewrites the frontmatter `id` in the same save: `stari-naslov-a1b2c3.md` becomes
`novi-naslov-a1b2c3.md`. The six-hex suffix is carried over, because it is the
part that identifies the entry; a target name that is already taken gets a fresh
suffix rather than overwriting another entry.

This is deliberately narrow:

- **MCP never renames.** `update_memory` keeps the id an agent was handed, so a
  later tool call cannot land on a file that no longer exists.
- **A published writing keeps its id** while `public` is set — that id is the URL
  `/public/writings/{id}` serves, so a retitle must not break its permalink.
- **Filenames this application did not generate are left alone**, because a file
  dropped in by hand may be linked from Obsidian. Only `{slug}-{6 hex}` ids move.
- An empty title never renames anything, and a title whose slug is unchanged
  (case, punctuation, accents) touches no file.

Anything that stores an id outside MemoryDown — a runner's `category/id`, a
bookmark, a link inside another note — therefore has to be updated after a
rename. The search index needs no cooperation: its signature includes each
file's relative path, so the rename triggers the ordinary lazy rebuild.

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

Matching folds diacritics (č, š, ž, ć, đ and the rest of the Latin letters), so
`posta` and `pošta` are the same word — the SQLite index does this in FTS5 and
the direct scan does it through `MemoryDown\Memory\Text`, so the two engines
cannot answer differently. A metadata result carries `chars`, the size of the
body it omits, so an agent knows what reading an entry would cost.

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

### Private entries (the owner's memory the AI may not read)

An entry may carry `private: true` in its frontmatter. It is a **state**, not a
tag, because it has to survive tag edits and cannot be lost by accident.

- **It is the one thing MCP cannot reach.** Private entries never appear in
  `search_memory`, `recall` or `list_memory`, are not counted, and
  `read_memory` / `update_memory` / `forget_memory` refuse them by id. The same
  holds on the admin connector `/mcp/all`: an administrator's second connection
  sees every *enabled account*, never anybody's private entries.
- **Only the human sets it, only in the admin UI.** The MCP tools have no field
  for it, `remember` cannot create one, and no agent can lift it. Like the
  reserved `executable` tag, it is a boundary the model cannot move.
- **Hidden everywhere an agent reads**, which is more than MCP: `/public/writings`
  withholds a private writing even if `public: true` (privacy wins, checked again
  at read time because the Markdown can be edited by hand), and `/api/workflows`
  withholds a private definition. `private` + `executable` is **refused** rather
  than honoured: a runner reads its definitions over that API, so a hidden one
  could never run — a silent failure instead of an error.
- **`private` and `public` are mutually exclusive.** Publishing serves an entry to
  a website; privacy keeps it away from AI. Both at once is refused with a reason
  instead of silently picking one.
- **Default hidden, everywhere.** `MemoryStore::list()`/`count()` and both search
  engines exclude private entries unless asked (`includePrivate`), so a caller
  that forgets to think about it hides rather than leaks. Only the admin UI — the
  owner's own view — asks, and it shows them with a lock badge and finds them with
  `is:private`.
- **It is not encryption.** The file is Markdown on disk: readable in Obsidian, in
  the backup zip and over FTP. The UI says so where the switch is.
- **Dedupe ignores them**: `remember` cannot silently rewrite a hidden entry; it
  creates a new one, and says so in its description.

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

The human-facing page of links lives at `/about`; the site root is not it. A
plain `GET /` redirects to the admin UI (`/ui`), which resolves to the sign-in
page, the first-run setup or the editor depending on the session — or shows the
links page itself when `UI_ENABLED=false`. `POST /` and `GET /` with
`Accept: text/event-stream` remain the MCP endpoint, because a client may probe
the base URL instead of `/mcp`.

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
  `run_if`, `mail_context`, `mailbox`, `mail_mark_read`, `llm_provider`,
  `llm_model`, `llm_temperature`) lives in a fenced `yaml` block **in the body**, because the
  frontmatter is rebuilt from a fixed key list on every save and would silently
  drop it. The block is the first thing in the body; the title is a separate
  field, written as the `# …` heading above it. `mode` is `dry-run` (default),
  `apply` (every mutating action confirmed by the user) or `yolo` (the allowlist
  runs unasked); `allowed_tools` takes a list of ids, a single id, or the
  wildcards `*` and `server.*`.
  `run_if` (`unread_mail` | `unread_mail_any`), `mail_context` (`unread`),
  `mailbox` (a name or path, default `INBOX`) and `mail_mark_read` (a boolean,
  default `false`) are the runner's conditions and context: they may skip a run,
  preload unread mail or mark it read, and none of them can widen permissions,
  grant a tool or change `mode`.
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
  nothing else *in the admin UI* — reading or editing somebody else's entries
  there is not offered, so the isolation is not undone by a convenience. (The one
  deliberate exception is the admin MCP connector, `/mcp/all`; see the amendment
  below.) The last enabled admin can be neither demoted, disabled nor deleted, and
  no one can do it to themselves.
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

### Amendment: the admin MCP connector (in scope)

An administrator can connect one extra MCP client that serves **every enabled
account at once**, under the constraints that follow. This is the single, explicit
exception to "one connector, one corpus"; nothing else about accounts changes.

- **It is its own endpoint and its own audience.** `/mcp/all`, next to `/mcp` and
  the base URL, with its own RFC 9728 metadata
  (`/.well-known/oauth-protected-resource/mcp/all`) and its own token `resource`.
  Tokens are **not** interchangeable: a token consented for `/mcp` is rejected at
  `/mcp/all` and vice versa. Reaching every account therefore takes its own walk
  through discovery, consent and a fresh token — it is never inherited from an
  ordinary connector.
- **Admin role is checked on every request, and refused as 403.** A valid token of
  a non-admin account is answered with `403` and no `WWW-Authenticate` header: the
  credentials are fine, the door is not open, and a 401 would send the client back
  into OAuth forever. A role change bumps the account's auth epoch, so a demoted
  admin's connector dies with it.
- **The same seven tools, with an `owner` argument.** Reads (`search_memory`,
  `recall`, `read_memory`, `list_memory`) search every enabled account when
  `owner` is omitted and one when it is given; every result carries the `owner` it
  came from. Writes (`remember`, `update_memory`, `forget_memory`) go to `owner`,
  defaulting to the acting account. There is no second implementation: the same
  `MemoryTools` handlers run, so the per-account protections — `writings`
  read-only, the reserved `executable` tag, the trash, the body-size ceiling —
  apply to a cross-account write exactly as they do to a local one.
- **On `/mcp` the `owner` argument does not exist.** It is absent from the tool
  schemas of the ordinary connector, so "reach another corpus" is not a call that
  can be expressed there. The restriction is structural, not a check that could be
  forgotten.
- **Disabled accounts are invisible**, in both directions and in both connectors:
  they are not searched, not listed, not readable and not writable. Disabling is
  still a credentials act (data is untouched and comes back on re-enable), but this
  connector is deliberately stricter than that.
- **Deletion stays recoverable, by the owner.** A cross-account `forget_memory`
  moves the file into *that* account's `memory/.trash/`, where only its owner can
  restore it in the admin UI. An administrator therefore treats a cross-account
  delete as permanent — which is what the tool description says.
- **The admin UI is unchanged.** It still shows and edits only the signed-in
  account's memory, and an admin still may only *download* another account's
  corpus. Cross-account reading and writing exist in one place — this connector —
  so the exception stays auditable.
- **Cross-account search merges, it does not reconcile.** Each account is searched
  through its own Markdown store and its own disposable index, and the results are
  merged: active before archived, then relevance, then freshness. bm25 scores from
  different corpora are not strictly comparable, which is why freshness breaks
  ties; per-account limits bound the work, and there is no new index, no new table
  and no background job.
- **Still out of scope here:** any other way to read another account's memory
  (UI browsing, a per-account admin switch, a shared corpus), and any notion of an
  administrator acting *as* another account — the token's `sub` always stays the
  admin who consented.

### Amendment: the interface language (in scope, UI only)

The admin UI, the sign-in/setup pages, `/about` and the OAuth consent page are
available in **Slovenian, English and Italian** — and nothing follows from that
beyond the interface:

- **The scope is the interface, not the data.** A translation changes what a page
  says, never what is stored: memories, titles, tags and workflow definitions are
  served exactly as written, and the MCP tool descriptions and server instructions
  stay English because agents read them. `/health`, the JSON API payloads and the
  protocol error codes are untranslated by design.
- **A category has a translated name and an untranslated identity.** The twelve
  built-in categories carry a display label per language (`category.*` in the
  catalogues, `I18n\Categories` resolves it, with the slug as the fallback for a
  folder somebody added by hand). The label is text and only text: the directory
  name, `?category=` in URLs, the `category` argument of `/ui/api/*`, every
  `data-*` attribute the editor compares against (the drop targets, the reserved
  `executable` rule, `public` being a `writings` flag), the MCP tools, the
  workflows API and the backup all keep the slug. Nothing may ever be *resolved*
  from a label — there is no reverse map, and a custom category colliding with
  one would make it ambiguous. The sidebar keeps slug order in every language.
- **One cookie, no account state.** `memorydown_lang`, one year, `HttpOnly`,
  `SameSite=Lax`, `Secure` in production — the same policy as the session cookie.
  Resolution order: cookie, `Accept-Language`, `APP_DEFAULT_LOCALE` (default
  `sl`). It is deliberately not stored in `users.json`: a language is a property
  of how somebody reads, not of who they are, and it has to work on the sign-in
  page where no account is known yet.
- **`GET /ui/language` is the only writer.** One cookie and a redirect, no
  JavaScript and no CSRF token (a display preference is not a mutation), and the
  return path must be a path inside this instance — a scheme, `//` or a backslash
  is dropped — so the endpoint can never become an open redirector. The cookie is
  not authentication: on its own it still lands on the sign-in page.
- **Catalogues are PHP arrays** (`src/I18n/lang/{sl,en,it}.php`), not gettext,
  because a `.mo` file needs a compiled locale that cheap shared hosting often
  lacks. English is the reference and the fallback, so a missing key reads as
  English rather than as a bare key. Plurals are per language
  (`I18n\Locale::pluralForm()`), which is what makes Slovenian's four forms
  (`1 vnos`, `2 vnosa`, `5 vnosov`) come out right.
- **The layers below the UI stay language-neutral.** `WorkflowDefinition` returns
  problem *codes* and their parameters and the sentences live in the catalogues,
  so a verdict can be Slovenian while the definition is untouched;
  `WebAuth::attempt()` returns refusal codes for the same reason.
- **Adding a language is one file plus one list entry**, and the unit tests
  enforce that every catalogue has the same keys, the same `{placeholders}` and
  the plural forms its language uses — a half-finished translation fails the build
  instead of showing keys in a page.

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