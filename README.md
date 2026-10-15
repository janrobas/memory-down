<img src="public_html/assets/logo.svg" alt="MemoryDown" width="96" height="96">

# MemoryDown

A tiny personal AI memory server in plain PHP. It gives ChatGPT and other MCP
clients persistent memory, stored as **Markdown files**, over MCP with OAuth 2.1.
Designed for cheap PHP shared hosting: no VPS, no Docker, no Node, no database
requirement, no long-running processes. FTP-deployable.

> **Disclaimer:** This project was vibe-coded with AI assistance. It works for
> its author's single-user use case.

```
ChatGPT / OpenCode / other MCP clients → MCP over HTTPS → OAuth 2.1 → MemoryDown → Markdown files
```

## Features

- **Markdown is the source of truth.** Each memory is a `.md` file with simple
  frontmatter, portable to Obsidian / VS Code / any editor.
- **MCP over Streamable HTTP**, serving both the `initialize` handshake
  (through `2025-11-25`) and the stateless `2026-07-28` revision.
- **OAuth 2.1** authorization-code + PKCE (S256), refresh-token rotation,
  RFC 9207 issuer identification, dynamic client registration (DCR), RFC 9728
  protected-resource metadata and RFC 8414 authorization-server metadata.
- **Seven MCP tools:** `remember`, `recall`, `search_memory`, `read_memory`,
  `update_memory`, `forget_memory`, `list_memory`. All categories except
  `writings` are read **and** write; `writings` is read-only through MCP.
- **Admin UI** at `/ui`: a two-pane browser/editor (search on the left,
  Markdown editor + live preview on the right) protected by a password. Themes,
  autosave, drag-to-move between categories. An **All / Active / Archived**
  filter, a per-memory **Archived** checkbox, and `tag:foo` search.
- **Search:** a disposable SQLite FTS5 index (BM25), rebuilt from the Markdown
  on demand, with automatic fallback to a direct file scan. Archived memories
  stay indexed and searchable but rank after active ones.
- **Read-only public writings API** at `/public/writings`: publish entries in the
  `writings` category (`public: true`) as Markdown over a token-gated GET API, for
  a personal website to consume. Disabled by default.
- **Read-only workflows API** at `/api/workflows`: the same idea for the
  `workflows` category, so a runner (or any tool) can read the definitions and
  their authorisation state over HTTP instead of the filesystem. Token-gated,
  disabled by default, and there is nothing to write with.
- **Workflow definitions** in `workflows`: a Markdown entry tagged `executable`
  is a definition a runner may execute, with its instructions in natural
  language and its configuration (`mode`, `schedule`, `timezone`,
  `allowed_tools`, `llm_provider`, `llm_model`) in a small YAML block in the body.
  The tag can only be added in the admin UI, and any entry carrying it is
  read-only through MCP — an agent can propose a workflow, never authorise or
  rewrite one. See [`docs/WORKFLOWS.md`](docs/WORKFLOWS.md).
- **Deletes are recoverable:** anything deleted — in the UI or by an agent — goes
  to a `.trash/` folder instead of vanishing, and can be restored until you empty
  it.
- **One-click backup** at `/ui/backup`: every Markdown file as a zip, with no
  credentials in it.
- **Diagnostics** at `/health`, `/health/mcp`, `/health/oauth`.
- **Filesystem-safe:** strict path whitelisting prevents traversal.

## Requirements

- **PHP 8.2+** (`composer.lock` is pinned to stay installable on 8.2/8.3/8.4;
  regenerate with a matching PHP if you target another version).
- Extensions: `fileinfo`, `mbstring`, `openssl` (`curl` recommended).
  Optional but recommended: `pdo_sqlite` (with FTS5) for the fast search index.
- Composer, used locally before uploading.

## Install

### Run locally

```bash
composer install
php -S 127.0.0.1:8080 -t public_html public_html/index.php
```

Open http://127.0.0.1:8080/health.

### Deploy (shared hosting, FTP)

1. `composer install --no-dev --optimize-autoloader` locally.
2. Upload the project via FTP (including `vendor/`).
3. Point the domain/subdomain **document root at `public_html/`**.
4. Make `data/` writable by PHP (`data/memory`, `data/auth`, `data/sessions`,
   `data/logs`, `data/index` are created/used there).
5. Copy `.env.example` to `.env`, fill it in, and upload it (see Configuration).
6. Open `https://your-domain/health` and confirm `{"status":"ok",...}`.
7. Set the admin password and open `/ui` (see Admin UI).
8. Connect ChatGPT (see below).

If you cannot change the document root, the root `.htaccess` denies everything
except `public_html/`; point the domain at the project root instead.

> **Composer + PHP version.** Run Composer with a PHP that matches the target
> host. On a machine with an older default `php`, call a newer binary explicitly
> (e.g. `php8.4 /path/to/composer.phar install ...`). To build for a host on
> 8.3: `composer config platform.php 8.3.0 && composer update --no-dev`.

### Upgrading an existing install

Back up `data/` and `.env`, then upload the new files. **Never overwrite
`data/` or `.env`** — they hold your memories, OAuth tokens and secrets (deleting
`data/auth/` forces every MCP client to re-authenticate). Run `composer install
--no-dev --optimize-autoloader` if dependencies changed. To roll back, restore
the previous code files; `data/` and `.env` are untouched.

## Configuration

Read in order: **real environment → `.env` file → built-in defaults**. Values set
in the hosting panel or php.ini win over `.env`.

| Variable | Default | Purpose |
| --- | --- | --- |
| `APP_BASE_URL` | `http://127.0.0.1:8080` | Public HTTPS base URL, no trailing slash |
| `APP_ENV` | `development` | `production` / `development` |
| `DATA_PATH` | `./data` | Runtime data (auth, sessions, index) |
| `MEMORY_PATH` | `./data/memory` | Where Markdown memory lives |
| `LOG_PATH` | `./data/logs` | Request log directory |
| `OAUTH_USERNAME` | *(empty)* | If set, the consent page requires this username |
| `OAUTH_CONSENT_PASSWORD` | *(empty)* | Consent-page password (plaintext or bcrypt hash; hash preferred) |
| `OAUTH_CIMD_ALLOWED_ORIGINS` | `chatgpt.com,localhost,127.0.0.1,::1` | Origins accepted as a CIMD fallback |
| `OAUTH_ACCESS_TOKEN_TTL` | `3600` | Access token lifetime (seconds) |
| `OAUTH_REFRESH_TOKEN_TTL` | `7776000` | Refresh token lifetime (seconds) |
| `OAUTH_CODE_TTL` | `600` | Authorization code lifetime (seconds) |
| `UI_ENABLED` | `true` | Enable the admin UI at `/ui` |
| `ADMIN_PASSWORD_HASH` | *(empty)* | Admin password as a bcrypt hash; if empty, set once via `/ui/setup` |
| `ADMIN_SETUP_TOKEN` | *(empty)* | Optional extra secret for the first-run `/ui/setup` form |
| `INDEX_ENABLED` | `true` | Build the SQLite FTS5 search index |
| `INDEX_PATH` | `./data/index/memory.sqlite` | Location of the disposable search index |
| `PUBLIC_API_ENABLED` | `false` | Serve published writings read-only at `/public/writings` |
| `PUBLIC_API_TOKEN` | *(empty)* | Bearer token required by the public writings API (required when enabled) |
| `WORKFLOWS_API_ENABLED` | `false` | Serve the `workflows` category read-only at `/api/workflows` |
| `WORKFLOWS_API_TOKEN` | *(empty)* | Bearer token required by the workflows API (required when enabled) |
| `RATE_LIMIT_ENABLED` | `true` | Per-IP rate limiting on the OAuth token + consent endpoints |
| `RATE_LIMIT_TOKEN_MAX` | `30` | Max token requests per IP per window |
| `RATE_LIMIT_CONSENT_MAX` | `10` | Max consent submissions per IP per window |
| `RATE_LIMIT_WINDOW` | `60` | Rate-limit window in seconds |

```
APP_BASE_URL=https://memory.example.com
APP_ENV=production
OAUTH_CONSENT_PASSWORD=a-long-random-password
```

## Admin UI (`/ui`)

A single-user, Obsidian-style web UI for browsing, searching and editing memories,
separate from the MCP/OAuth layer.

- **Left pane:** live search above memories grouped by category, with an
  **All / Active / Archived** filter. Search accepts `tag:foo` to require a tag
  (e.g. `tag:project-x notes`). Archived memories stay searchable and are shown
  with an `archived` badge, ranked after active ones.
- **Right pane:** title, category, tags, Markdown editor with a **Preview** tab
  (rendered server-side by `league/commonmark`, raw HTML escaped). The
  **Archived** checkbox archives/restores an entry (kept as frontmatter, never
  deleted). **Autosave** (on switching memories, on blur, and after ~20s idle);
  `Ctrl/Cmd+S` forces a save. Drag a memory onto a category to move it. Themes
  (white/dark/retro/green/blue) are remembered in `localStorage`.
- **Delete** appears next to the title for existing memories. It moves the entry
  to the trash (see below) rather than destroying it.
- **Menu:** *Reindex*, *Backup* (download a zip of your memory) and *Trash*
  (restore or purge deleted entries), plus the theme picker and sign-out.

The UI will not open until an admin password exists:

```bash
php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"
# put the result in ADMIN_PASSWORD_HASH in .env
```

Or open `/ui` on a fresh install and use the one-time `/ui/setup` page
(hash stored in `data/auth/ui.json`). For a public deployment, set
`ADMIN_SETUP_TOKEN` first so a stranger cannot claim the instance, then remove it.

Sessions live in `data/sessions/`; the cookie is `HttpOnly` + `SameSite=Lax`
(`Secure` in production). All state-changing requests require a CSRF token.

## Public writings API (`/public/writings`)

An optional, read-only, token-gated JSON API for publishing a subset of your
memory to another site (e.g. a personal blog). It only ever reads entries in the
`writings` category that carry `public: true` — nothing else in your memory is
reachable through it. It is disabled by default.

Enable it in `.env`:

```
PUBLIC_API_ENABLED=true
PUBLIC_API_TOKEN=a-long-random-secret
```

Your site's backend then sends the token on every request:

```
Authorization: Bearer a-long-random-secret
```

| Endpoint | Returns |
| --- | --- |
| `GET /public/writings?limit=50&offset=0` | `{ writings: [{ id, title, archived, tags, created, updated }], count, total, has_more }`, newest-**created** first (an edit never bumps a post) |
| `GET /public/writings/{id}` | `{ writing: { id, title, archived, tags, created, updated, markdown } }` |

The body is Markdown (render it on your site). Responses carry an `ETag` and
`Cache-Control: private`, and answer `If-None-Match` with `304`. An entry can be
both **public and archived** — it stays served, with `archived: true` in the
payload — so you can drop a post from listings without unpublishing it.

To publish: move the entry into the **writings** category and tick **Public** in
the admin UI. The flag is only shown for that category, and is never settable
through MCP, so an agent cannot publish on your behalf. Enabled without a
`PUBLIC_API_TOKEN` the API fails closed with `503`; disabled, the routes return
`404`.

The whole `writings` category is **read-only through MCP**: agents can `recall`,
`search_memory`, `read_memory` and `list_memory` its entries, but `remember`
refuses to store into it, `update_memory` and `forget_memory` refuse to touch an
entry that lives there, and nothing can be moved into it. An agent can read your
posts but never create, edit, archive, relocate or delete one — that happens only
in the admin UI. Every other category stays read **and** write for MCP.

### Example

```bash
# List published writings (newest first)
curl -s https://memory.example.com/public/writings \
  -H "Authorization: Bearer a-long-random-secret"

# Fetch one writing as Markdown
curl -s https://memory.example.com/public/writings/my-post-a1b2c3 \
  -H "Authorization: Bearer a-long-random-secret"
```

Consuming it from your site's PHP backend:

```php
$opts = ['http' => [
    'header' => 'Authorization: Bearer ' . getenv('MEMORYDOWN_TOKEN') . "\r\n",
    'ignore_errors' => true,
]];
$ctx  = stream_context_create($opts);
$list = json_decode((string) @file_get_contents('https://memory.example.com/public/writings', false, $ctx), true);

foreach ($list['writings'] ?? [] as $w) {
    // $w['id'], $w['title'], $w['tags'], $w['created'], $w['archived']
}
```

## Workflows API (`/api/workflows`)

The same shape of API as the writings one, for the other direction: a runner — or
any tool you point at it — can read your workflow definitions over HTTP instead
of the filesystem. It only ever reads the `workflows` category. Disabled by
default, and read-only by construction: only `GET` is routed, so there is no way
to write through it, and the Markdown files stay the source of truth.

Enable it in `.env` with its **own** key, separate from `PUBLIC_API_TOKEN`:

```
WORKFLOWS_API_ENABLED=true
WORKFLOWS_API_TOKEN=a-long-random-secret
```

Generate the key with `php -r "echo bin2hex(random_bytes(32));"`. Requests then
send `Authorization: Bearer a-long-random-secret`.

| Endpoint | Returns |
| --- | --- |
| `GET /api/workflows?limit=50&offset=0[&executable=true\|false]` | `{ workflows: [{ id, title, executable, archived, tags, created, updated }], count, total, has_more }`, newest-**created** first |
| `GET /api/workflows/{id}` | `{ workflow: { id, title, executable, archived, tags, created, updated, markdown, config, instructions } }` |

- **`executable`** is the authorisation flag from `docs/WORKFLOWS.md`: `true` when
  the entry carries the reserved tag, so a runner's discovery query is
  `GET /api/workflows?executable=true`.
- **The list carries metadata only**; the body is in the detail endpoint.
  `markdown` is the body exactly as stored — the YAML block followed by the
  instructions — and `config` / `instructions` are the same content parsed, for
  consumers that would rather not parse it themselves.
- **No verdict is published.** Whether a definition may run is decided by the
  runner's own checks (`docs/WORKFLOWS.md` §8); reading a definition here
  authorises nothing.
- Both endpoints carry an `ETag` and `Cache-Control: private`, and answer
  `If-None-Match` with `304`. Enabled without a `WORKFLOWS_API_TOKEN` the API
  fails closed with `503`; disabled, the routes return `404`.

```bash
# The definitions a runner is allowed to execute
curl -s "https://memory.example.com/api/workflows?executable=true" \
  -H "Authorization: Bearer a-long-random-secret"

# One definition, configuration and instructions
curl -s https://memory.example.com/api/workflows/jutranji-pregled-poste-a1b2c3 \
  -H "Authorization: Bearer a-long-random-secret"
```

Treat the key like a password: whoever holds it can read your automation
surface — the instructions, the allowed tools and the schedules.

## Workflows (`workflows`)

The `workflows` category holds two different things, told apart by one tag:

| Entry | Meaning |
| --- | --- |
| in `workflows`, **no** `executable` tag | a note, draft or description of a process. Never executed. |
| in `workflows`, **with** `executable` | a workflow definition a runner (see below) may execute |

A definition keeps its instructions in natural language in the body, under a
small YAML block that carries its operational configuration. Creating a new
memory in `workflows` in the admin UI **pre-fills that body** with the canonical
template, so the shape is never guessed:

````markdown
# Preverjanje e-pošte in koledarja

```yaml
# schedule: "0 7,12 * * *"
timezone: Europe/Ljubljana
mode: dry-run
allowed_tools:
  - calendar.search_emails
  - calendar.get_events
llm_provider: opencode-go
llm_model: <model-id>
```

## Namen

TODO: kaj naj workflow naredi in zakaj.

## Navodila

1. TODO: koraki, ki naj jih izvajalec izvede.
````

- **Configuration lives in the body, not in the frontmatter.** MemoryDown
  rebuilds the frontmatter from a fixed set of keys on every save, so any other
  key there is dropped by the next autosave; the body survives every write. The
  block must be the first thing in the body — the title is a separate field and
  is written as the `# …` heading above it.
- **`mode`** is `dry-run` (read-only: it reports what it *would* do) or `apply`
  (may change things, each mutating action confirmed by you). It defaults to
  `dry-run`, so a newly activated workflow cannot write anything by accident.
- **`schedule`** is a 5-field cron expression in `timezone` (default
  `Europe/Ljubljana`); omit it for a manual-only workflow. The template leaves it
  commented out, so a new definition starts manual.
- **`allowed_tools`** is the allowlist of tools the workflow may call. Anything
  not listed is refused, and every tool that changes state needs your explicit
  confirmation at the moment it is called.
- **`llm_provider` / `llm_model`** name the model to run with. Left out, the
  runner uses its own defaults.
- **The template is a draft on purpose**: it still contains `TODO` and a
  placeholder model, which a runner refuses to execute. Once enabled, the editor
  shows the same verdict you would get — *a runner may execute this definition*,
  or the list of reasons it would refuse — so a half-filled definition cannot
  look finished.
- **The `executable` tag is offered only in `workflows`**: outside it the admin
  UI does not suggest it and the admin API refuses it, and an activated workflow
  cannot be moved out of the category until the tag is removed. That is the same
  permission seen from the other side — a runner only ever looks in `workflows`.
- **Pause** a workflow by archiving it (the *Archived* checkbox) or by removing
  the tag.

Nothing in MemoryDown runs anything yet: this is the definition format and the
rules a future C#/.NET Worker Service will follow.
[`docs/WORKFLOWS.md`](docs/WORKFLOWS.md) is the normative document (in
Slovenian): the full field list, the validation rules a runner must apply before
executing, and the safety model. A runner that cannot read your filesystem can
fetch definitions through the read-only [workflows API](#workflows-api-apiworkflows)
instead — same Markdown, one more way to read it.

## Search index

Search uses a **disposable SQLite FTS5 index** (`INDEX_PATH`). Markdown stays the
source of truth; the index is a cache. It rebuilds automatically when the corpus
changes, can be rebuilt via `POST /ui/reindex`, and falls back to a direct
`.md` scan when `pdo_sqlite`/FTS5 is unavailable. Deleting it loses nothing.
Every `.md` file is indexed, including archived memories (archiving sets an
`archived: true` frontmatter key; the file is never moved or hidden). Search
results carry a ranked **snippet** taken from the index; the `read_memory` MCP
tool and the admin editor read the file itself, which is where the complete body
comes from.

## Backup, trash & restore

### Download a backup

In the admin UI, **Backup** (or `GET /ui/backup`) downloads a zip of every file in
your memory directory, named `memorydown-backup-YYYY-MM-DD-HHMM.zip`. It contains:

| In the zip | Not in the zip |
| --- | --- |
| `memory/**` — every `.md`, the root files, and `.trash/` | `data/index/` (disposable, rebuilds itself) |
| `MANIFEST.json` — when it was made, from which version, how many memories | `data/sessions/`, `data/logs/` |
| `RESTORE.txt` — the instructions, so the zip explains itself later | `data/auth/` and `.env` — **never included**, so a download can never leak a credential |

**Restore:** unzip it and upload the `memory/` folder so its contents land in
`MEMORY_PATH` (default `data/memory`), keeping the category subfolders. That is
the whole procedure: the Markdown files are the source of truth, and the search
index rebuilds itself on the next search or `POST /ui/reindex`.

Restoring `memory/` alone is always enough to bring your memories back. It does
not bring back `data/auth/` (so your MCP clients re-authorize) or `.env` (which
you re-enter by hand) — worth knowing before you need it.

A download is a point-in-time copy, and nothing schedules it for you: take one
whenever you are about to do something you might regret. `data/memory` is also
just a directory, so copying it over FTP or your host's file manager is
equivalent.

### Deletes are recoverable

Deleting a memory — the **Delete** button, or an agent calling `forget_memory` —
moves the file to `data/memory/.trash/` instead of deleting it. The trash lives
at `/ui/trash`, where each entry shows where it came from and can be **Restored**
to its original category or **Deleted forever**. **Empty the trash** clears the
whole thing; that is the only operation in MemoryDown that really destroys data.

Trashed entries are invisible everywhere else — the editor, search, `recall`,
`list_memory` and the public writings API all ignore `.trash/`, because a
dot-prefixed name can never be a category. If the trash directory cannot be
written, a delete **fails with an explanation instead of destroying the entry**,
which is the point of having one.

## OAuth & connecting ChatGPT

MemoryDown is its own single-user OAuth 2.1 authorization server.

| Endpoint | Purpose |
| --- | --- |
| `/.well-known/oauth-protected-resource` and `.../mcp` | RFC 9728 resource metadata |
| `/.well-known/oauth-authorization-server` / `/.well-known/openid-configuration` | RFC 8414 / OIDC discovery |
| `/oauth/authorize` | Consent page |
| `/oauth/token` | Token endpoint |
| `/oauth/register` | Dynamic client registration (RFC 7591) |

Clients register via **DCR** (like a typical single-user MCP server) and run the
authorization-code + PKCE flow. CIMD is not advertised but is accepted as a
fallback for allow-listed origins. Public clients only (`none` + PKCE).

To connect ChatGPT: enable **developer mode**, create a new connector, enter your
server URL (e.g. `https://memory.example.com/mcp`), choose **OAuth**, and approve
the consent page. The server answers MCP at both `/` and `/mcp`, so it works
whether ChatGPT probes the base URL or the path.

After connecting, paste the block from [`CHATGPT-INSTRUCTIONS.md`](CHATGPT-INSTRUCTIONS.md)
into ChatGPT's custom instructions so it uses MemoryDown by default instead of its
built-in memory.

## Testing & diagnostics

```bash
curl -s https://memory.example.com/health
composer test              # unit tests + full protocol chain
php tests/unit-tests.php   # frontmatter (incl. CRLF), path guards, search query, rate limiter
php tests/run-tests.php    # chain: health → discovery → 401 → DCR → token → MCP → tools → traversal
```

`/health` reports `checks.search_index.engine` as `sqlite-fts5` (indexed) or
`direct` (fallback).

## Security notes

- Memory paths are whitelisted (categories/ids by regex plus canonical-path
  containment); traversal and deletion outside the memory root are rejected.
- `writings` is read-only through MCP: the write tools refuse to create, edit,
  move into or delete entries in that category.
- The `executable` tag is reserved and human-only: it can only be added in the
  admin UI, and `remember`, `update_memory` and `forget_memory` refuse to touch
  any entry that carries it. A workflow definition cannot be created, rewritten,
  moved, archived or deleted by an agent — only proposed, for you to activate.
- Deletes are soft: entries move to `memory/.trash/` and are restorable until the
  trash is emptied, and a broken trash directory fails the delete rather than
  silently unlinking the file.
- The backup download is session-guarded and contains **only** `memory/**` — no
  auth state, no `.env`, no tokens. It is a plain `GET`, so a hostile page could
  make your browser start that download, but it cannot read the response; the
  bytes land on your own disk.
- Access tokens are audience-bound to the requested `resource`; tokens and codes
  are stored only as SHA-256 hashes.
- Secrets are never logged; error messages are scrubbed of the consent password.
- All responses carry security headers; the admin pages use a `script-src 'self'`
  CSP, every other route keeps `default-src 'none'`.
- Set `OAUTH_CONSENT_PASSWORD` (ideally a hash) so the consent page is not
  click-through-only.
- The OAuth token and consent endpoints are rate-limited per IP (file-backed,
  no daemon); disable with `RATE_LIMIT_ENABLED=false`.
- `.env` and other dotfiles/config are denied by the root `.htaccess`, and the
  preferred deployment keeps them outside `public_html/` entirely.

## Limitations

- Single-user only (one admin password, one OAuth consent). Run one instance per
  person to host several people.
- Keyword/BM25 search — no embeddings or vector search.
- Public OAuth clients only (`none` + PKCE); no revocation endpoint.
- `mcp/sdk` is pre-1.0 (experimental); pin it in `composer.lock`.

## Troubleshooting

- **`composer install` says PHP version mismatch** — you ran Composer with an
  older PHP; use 8.2+, or pin `platform.php` to the host version.
- **Search shows `engine: direct`** — `pdo_sqlite` is not enabled; the app still
  works, searching files directly.
- **`/ui` returns `internal_error`** — check `data/logs/app.log`; usually
  `data/sessions/` or `data/index/` not writable, or `vendor/` not uploaded.

See `AGENTS.md` for the project's core principles and constraints.
