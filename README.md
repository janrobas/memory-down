<img src="public_html/assets/logo.svg" alt="MemoryDown" width="96" height="96">

# MemoryDown

A tiny personal AI memory server in plain PHP. It gives ChatGPT and other MCP
clients persistent memory, stored as **Markdown files**, over MCP with OAuth 2.1.
Designed for cheap PHP shared hosting: no VPS, no Docker, no Node, no database
requirement, no long-running processes. FTP-deployable.

One instance serves several people: every account has its own memory, its own
username and password, and its own API tokens — the same credential opens the
admin UI and the MCP consent page.

> **Disclaimer:** This project was vibe-coded with AI assistance. It works for
> its author's personal use case.

```
ChatGPT / OpenCode / other MCP clients → MCP over HTTPS → OAuth 2.1 → MemoryDown → Markdown files (one directory per account)
```

## Features

- **Markdown is the source of truth.** Each memory is a `.md` file with simple
  frontmatter, portable to Obsidian / VS Code / any editor.
- **Accounts.** Every account gets its own directory (`data/users/<username>/`),
  its own password and its own API tokens. There is no query, no search and no
  file operation that can reach across that boundary, and admins manage the
  accounts at `/ui/users`.
- **One password per person**, used for both the admin UI and the OAuth consent
  page. Changing it invalidates that account's other sessions and MCP tokens
  immediately.
- **MCP over Streamable HTTP**, serving both the `initialize` handshake
  (through `2025-11-25`) and the stateless `2026-07-28` revision.
- **OAuth 2.1** authorization-code + PKCE (S256), refresh-token rotation,
  RFC 9207 issuer identification, dynamic client registration (DCR), RFC 9728
  protected-resource metadata and RFC 8414 authorization-server metadata. One
  authorization server serves the whole instance; *which* account a connection
  reaches is decided on the consent page.
- **Seven MCP tools:** `remember`, `recall`, `search_memory`, `read_memory`,
  `update_memory`, `forget_memory`, `list_memory`. All categories except
  `writings` are read **and** write; `writings` is read-only through MCP.
- **Admin UI** at `/ui`: a two-pane browser/editor (search on the left,
  Markdown editor + live preview on the right) protected by a username and
  password. Themes, autosave, drag-to-move between categories. An
  **All / Active / Archived** filter, a per-memory **Archived** checkbox, and
  `tag:foo` search.
- **Settings** at `/ui/settings` (your own password and API tokens) and
  **Users** at `/ui/users` (admins only: create, disable, reset, promote, delete).
- **Search:** a disposable SQLite FTS5 index (BM25) per account, rebuilt from the
  Markdown on demand, with automatic fallback to a direct file scan. Archived
  memories stay indexed and searchable but rank after active ones.
- **Read-only public writings API** at `/public/writings`: publish entries in the
  `writings` category (`public: true`) as Markdown over a token-gated GET API, for
  a personal website to consume. Disabled by default; the token is per account.
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
  it. Deleting an *account* moves its whole directory aside the same way.
- **One-click backup** at `/ui/backup`: every Markdown file of one account as a
  zip, with no credentials in it; an admin can download any account's.
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
4. Make `data/` writable by PHP (`data/users`, `data/auth`, `data/sessions`,
   `data/logs` are created/used there; each account's memory and index go inside
   `data/users/<username>/`).
5. Copy `.env.example` to `.env`, fill it in, and upload it (see Configuration).
6. Open `https://your-domain/health` and confirm `{"status":"ok",...}`.
7. Open `/ui` and create the first account (see Admin UI).
8. Connect ChatGPT (see below), signing in on the consent page as that account.

If you cannot change the document root, the root `.htaccess` denies everything
except `public_html/`; point the domain at the project root instead.

> **Composer + PHP version.** Run Composer with a PHP that matches the target
> host. On a machine with an older default `php`, call a newer binary explicitly
> (e.g. `php8.4 /path/to/composer.phar install ...`). To build for a host on
> 8.3: `composer config platform.php 8.3.0 && composer update --no-dev`.

### Upgrading an existing install

Back up `data/` and `.env`, then upload the new files. **Never overwrite
`data/` or `.env`** — they hold your memories, accounts, OAuth tokens and secrets
(deleting `data/auth/` forces every MCP client to re-authenticate). Run
`composer install --no-dev --optimize-autoloader` if dependencies changed. To
roll back, restore the previous code files; `data/` and `.env` are untouched.

<details>
<summary>Upgrading from a version without accounts</summary>

The first request after the upgrade migrates a single-user instance to accounts,
once, with no manual step:

1. It creates the first **admin** account from what the old configuration had:
   the username from `OAUTH_USERNAME` (or `admin`), and the password from
   `ADMIN_PASSWORD_HASH`, else the hash an older version stored in
   `data/auth/ui.json`, else `OAUTH_CONSENT_PASSWORD`. Where the admin UI password
   and the consent password differed, the **admin UI password wins** — that is
   the one the instance owner knows, and consolidating onto one password per
   person is the point of the change. The other one stops working.
2. It moves the corpus: every category directory and the `.trash` of
   `MEMORY_PATH` (default `data/memory`) becomes that admin's memory. Anything
   else at that root — the explanatory `README.md` — stays where it is.

From then on those environment variables are **ignored** (they were only seed
material), which is what makes changing a password in the UI stick: previously
`ADMIN_PASSWORD_HASH` overrode the stored hash forever.

Seeding happens once, recorded in `data/auth/migration.json`. If
`data/auth/users.json` is later deleted, the instance is *unowned* rather than
re-seeded from stale environment values — the way back in is `/ui/setup`, guarded
by `ADMIN_SETUP_TOKEN`.

After migrating you can delete `data/memory` (its corpus has moved) and
`data/index` (the index is per account now, and disposable), along with the
retired `MEMORY_PATH` / `INDEX_PATH` settings. Keep a copy until you are happy.
</details>

## Configuration

Read in order: **real environment → `.env` file → built-in defaults**. Values set
in the hosting panel or php.ini win over `.env`.

| Variable | Default | Purpose |
| --- | --- | --- |
| `APP_BASE_URL` | `http://127.0.0.1:8080` | Public HTTPS base URL, no trailing slash |
| `APP_ENV` | `development` | `production` / `development` |
| `DATA_PATH` | `./data` | Runtime data (accounts, auth, sessions, logs) |
| `USERS_PATH` | `./data/users` | Where each account's memory and index live |
| `LOG_PATH` | `./data/logs` | Request log directory |
| `MCP_SESSIONS_PATH` | `./data/sessions/mcp` | MCP transport sessions, one directory per account |
| `MEMORY_PATH` | `./data/memory` | **Deprecated, migration only**: the corpus of a pre-accounts instance, read once |
| `OAUTH_USERNAME` | *(empty)* | **Deprecated**: seed username for the first account |
| `OAUTH_CONSENT_PASSWORD` | *(empty)* | **Deprecated**: seed password for the first account |
| `OAUTH_CIMD_ALLOWED_ORIGINS` | `chatgpt.com,localhost,127.0.0.1,::1` | Origins accepted as a CIMD fallback |
| `OAUTH_ACCESS_TOKEN_TTL` | `3600` | Access token lifetime (seconds) |
| `OAUTH_REFRESH_TOKEN_TTL` | `7776000` | Refresh token lifetime (seconds) |
| `OAUTH_CODE_TTL` | `600` | Authorization code lifetime (seconds) |
| `UI_ENABLED` | `true` | Enable the admin UI at `/ui` |
| `ADMIN_PASSWORD_HASH` | *(empty)* | **Deprecated**: seed password hash for the first account; otherwise set the first one via `/ui/setup` |
| `ADMIN_SETUP_TOKEN` | *(empty)* | Optional extra secret for the first-run `/ui/setup` form (and the only way back into an instance whose account store was deleted) |
| `INDEX_ENABLED` | `true` | Build the SQLite FTS5 search index (one per account) |
| `PUBLIC_API_ENABLED` | `false` | Serve published writings read-only at `/public/writings` |
| `WORKFLOWS_API_ENABLED` | `false` | Serve the `workflows` category read-only at `/api/workflows` |
| `RATE_LIMIT_ENABLED` | `true` | Per-IP rate limiting on the OAuth token + consent endpoints |
| `RATE_LIMIT_TOKEN_MAX` | `30` | Max token requests per IP per window |
| `RATE_LIMIT_CONSENT_MAX` | `10` | Max consent submissions per IP per window |
| `RATE_LIMIT_WINDOW` | `60` | Rate-limit window in seconds |

There is **no setting for accounts, passwords or API tokens**: they live in
`data/auth/users.json` and are managed in the admin UI, so a leaked copy of
`.env` is not a copy of everybody's credentials. `.env` decides how the instance
behaves, not who may use it.

```
APP_BASE_URL=https://memory.example.com
APP_ENV=production
```

## Admin UI (`/ui`)

An Obsidian-style web UI for browsing, searching and editing memories, separate
from the MCP/OAuth layer. Sign in with your **username and password** — the same
pair the MCP consent page asks for.

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
- **Menu:** *Reindex*, *Backup* (download a zip of your memory), *Trash*
  (restore or purge deleted entries), *Settings*, and *Users* for admins, plus
  the theme picker and sign-out.

### First run

Open `/ui` on a fresh install: with no accounts it shows a one-time `/ui/setup`
page that creates the first **admin** account. Set `ADMIN_SETUP_TOKEN` beforehand
so a stranger cannot claim the instance, then remove it from `.env`.

An instance upgraded from a single-user version already has its admin (see
Upgrading) and goes straight to the login page.

### Settings (`/ui/settings`)

Your own password and your own API tokens, for anybody who is signed in:

- **Password.** This is also the password MCP clients use on the consent page.
  Changing it invalidates every other session and every MCP token of that
  account and signs them out; you stay signed in where you changed it.
- **API tokens.** One per publishing API (`writings`, `workflows`). Generate,
  rotate and clear them here, and read them back whenever you need to copy one
  somewhere. *Rotating* is immediate: whatever used the old token stops working.
- **Backup** of your own memory.

### Users (`/ui/users`, admins only)

Create an account (username, role and password; the username becomes that
person's data directory and cannot be changed later), promote or demote, disable,
reset a password, hand out an API token, and delete.

- **An admin sees the accounts and can download their memory. Nothing else.**
  There is no way to browse or edit another account's entries from the UI: the
  isolation the storage layout gives is not undone by a convenient button.
- **Disabling** an account stops it everywhere at once — sessions, MCP tokens and
  API tokens — and **re-enabling does not revive them**, because a token is
  stamped with the account's credential epoch.
- **Deleting** an account removes its record and moves its whole directory to
  `data/users/.trash/<timestamp>__<username>/`. Nothing is erased; nothing in the
  app can see it again, and you can put it back over FTP.
- You cannot demote, disable or delete yourself, and the **last enabled admin**
  cannot be removed by anybody — an instance with no admin has no way back except
  editing `data/auth/users.json` by hand.

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
```

and generate a token in the admin UI under **Settings → API tokens**. Each
account has its own, and the token is what decides *whose* writings are served:
one person's key can never read another person's corpus. Your site's backend then
sends it on every request:

```
Authorization: Bearer <your token>
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
through MCP, so an agent cannot publish on your behalf. A request without a
token, or with one that belongs to nobody (or to a disabled account), gets `401`;
disabled, the routes return `404`.

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
  -H "Authorization: Bearer $MEMORYDOWN_TOKEN"

# Fetch one writing as Markdown
curl -s https://memory.example.com/public/writings/my-post-a1b2c3 \
  -H "Authorization: Bearer $MEMORYDOWN_TOKEN"
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

Enable it in `.env`:

```
WORKFLOWS_API_ENABLED=true
```

and generate the token in the admin UI under **Settings → API tokens** — it is
per account, exactly like the writings one, and the token decides whose
definitions are served. Requests then send `Authorization: Bearer <token>`.

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
  `If-None-Match` with `304`. A request without a token that belongs to an
  enabled account gets `401`; disabled, the routes return `404`.

```bash
# The definitions a runner is allowed to execute
curl -s "https://memory.example.com/api/workflows?executable=true" \
  -H "Authorization: Bearer $MEMORYDOWN_WORKFLOWS_TOKEN"

# One definition, configuration and instructions
curl -s https://memory.example.com/api/workflows/jutranji-pregled-poste-a1b2c3 \
  -H "Authorization: Bearer $MEMORYDOWN_WORKFLOWS_TOKEN"
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

Search uses a **disposable SQLite FTS5 index** (one per account, at
`data/users/<username>/index/memory.sqlite`). Markdown stays the source of truth;
the index is a cache. It rebuilds automatically when the corpus changes, can be
rebuilt via `POST /ui/reindex`, and falls back to a direct `.md` scan when
`pdo_sqlite`/FTS5 is unavailable. Deleting it loses nothing.
Every `.md` file is indexed, including archived memories (archiving sets an
`archived: true` frontmatter key; the file is never moved or hidden). Search
results carry a ranked **snippet** taken from the index; the `read_memory` MCP
tool and the admin editor read the file itself, which is where the complete body
comes from.

## Backup, trash & restore

### Download a backup

In the admin UI, **Backup** (or `GET /ui/backup`) downloads a zip of every file in
*your* memory directory, named `memorydown-<username>-backup-YYYY-MM-DD-HHMM.zip`.
An admin can fetch any account's memory with `GET /ui/backup?user=<username>`, or
from the *Users* page — that read-only download is the one thing an admin can do
with somebody else's memory. The zip contains:

| In the zip | Not in the zip |
| --- | --- |
| `memory/**` — every `.md`, the root files, and `.trash/` | the search index (disposable, rebuilds itself) |
| `MANIFEST.json` — when it was made, from which version, which account, how many memories | `data/sessions/`, `data/logs/` |
| `RESTORE.txt` — the instructions, so the zip explains itself later | `data/auth/` and `.env` — **never included**, so a download can never leak a credential or another account's data |

**Restore:** unzip it and upload the `memory/` folder so its contents land in
that account's memory directory (`data/users/<username>/memory`, keeping the
category subfolders). That is the whole procedure: the Markdown files are the
source of truth, and the search index rebuilds itself on the next search or
`POST /ui/reindex`.

Restoring `memory/` alone is always enough to bring that person's memories back.
It does not bring back accounts (`data/auth/users.json`, so a password has to be
set again) or `.env` (which you re-enter by hand) — worth knowing before you need
it.

A download is a point-in-time copy, and nothing schedules it for you: take one
whenever you are about to do something you might regret. Each account's memory
directory is also just a directory, so copying it over FTP or your host's file
manager is equivalent.

### Deletes are recoverable

Deleting a memory — the **Delete** button, or an agent calling `forget_memory` —
moves the file to that account's `.trash/` instead of deleting it. The trash lives
at `/ui/trash`, where each entry shows where it came from and can be **Restored**
to its original category or **Deleted forever**. **Empty the trash** clears the
whole thing; that is the only operation in MemoryDown that really destroys data.

Trashed entries are invisible everywhere else — the editor, search, `recall`,
`list_memory` and the public writings API all ignore `.trash/`, because a
dot-prefixed name can never be a category. If the trash directory cannot be
written, a delete **fails with an explanation instead of destroying the entry**,
which is the point of having one.

Deleting an **account** follows the same rule one level up: its record goes, and
its whole directory is parked in `data/users/.trash/<timestamp>__<username>/`.

## OAuth & connecting ChatGPT

MemoryDown is its own OAuth 2.1 authorization server, one per instance, shared by
every account.

| Endpoint | Purpose |
| --- | --- |
| `/.well-known/oauth-protected-resource` and `.../mcp` | RFC 9728 resource metadata |
| `/.well-known/oauth-authorization-server` / `/.well-known/openid-configuration` | RFC 8414 / OIDC discovery |
| `/oauth/authorize` | Consent page (sign in as the account to connect) |
| `/oauth/token` | Token endpoint |
| `/oauth/register` | Dynamic client registration (RFC 7591) |

Clients register via **DCR** (like a typical MCP server) and run the
authorization-code + PKCE flow. CIMD is not advertised but is accepted as a
fallback for allow-listed origins. Public clients only (`none` + PKCE).

The consent page asks for a **username and password** — your account, the same
one you use at `/ui` — and the token that comes out is bound to that account
(OAuth `sub`). So the endpoint is one URL for everybody, and *who you sign in as*
decides whose memory the connection reaches; a token stops working the moment
that account is disabled or its password changes. MCP transport sessions are kept
per account too, so a session belonging to one account cannot be resumed by
another.

To connect ChatGPT: enable **developer mode**, create a new connector, enter your
server URL (e.g. `https://memory.example.com/mcp`), choose **OAuth**, then sign in
as the account that connection is for and approve. The server answers MCP at both
`/` and `/mcp`, so it works whether ChatGPT probes the base URL or the path.

After connecting, paste the block from [`CHATGPT-INSTRUCTIONS.md`](CHATGPT-INSTRUCTIONS.md)
into ChatGPT's custom instructions so it uses MemoryDown by default instead of its
built-in memory.

## Testing & diagnostics

```bash
curl -s https://memory.example.com/health
composer test              # unit tests + full protocol chain
php tests/unit-tests.php   # frontmatter (incl. CRLF), path guards, accounts, migration, search query, rate limiter
php tests/run-tests.php    # chain: health → discovery → 401 → DCR → consent → token → MCP → tools → accounts → traversal
```

`/health` reports `checks.users` (how many accounts exist) and
`checks.search_index.engine` as `sqlite-fts5` (indexed) or `direct` (fallback).
`/health/mcp` lists the tools and reports whether MCP session storage is
writable.

`/health` reports `checks.search_index.engine` as `sqlite-fts5` (indexed) or
`direct` (fallback).

## Security notes

- **Accounts are the isolation boundary.** Every account's memory lives in its
  own directory, with its own store, its own search index and its own MCP
  sessions, and a bearer token names the account it may act as. There is no
  query, path or tool call that reaches across that line — and the admin UI does
  not offer a convenient way around it either: an admin can download another
  account's memory, never browse or edit it.
- Memory paths are whitelisted (categories/ids by regex plus canonical-path
  containment); traversal and deletion outside the memory root are rejected.
  Account ids get the same treatment, because an id is a directory name.
- `writings` is read-only through MCP: the write tools refuse to create, edit,
  move into or delete entries in that category.
- The `executable` tag is reserved and human-only: it can only be added in the
  admin UI, and `remember`, `update_memory` and `forget_memory` refuse to touch
  any entry that carries it. A workflow definition cannot be created, rewritten,
  moved, archived or deleted by an agent — only proposed, for you to activate.
- Passwords are stored as one-way hashes and never logged. Each account has one,
  used for both the admin UI and the MCP consent page.
- Tokens carry the account's **credential epoch**: changing a password, changing
  a role, disabling or deleting an account invalidates every session, MCP token
  and API token it held, without a sweep and without a daemon. Re-enabling an
  account does not revive them.
- Deleting is soft at both levels: memories move to `memory/.trash/`, accounts
  move to `data/users/.trash/`, and a broken trash directory fails the delete
  rather than silently unlinking the file.
- The backup download is session-guarded and contains **only** one account's
  `memory/**` — no accounts, no auth state, no `.env`, no tokens, no other
  account. It is a plain `GET`, so a hostile page could make your browser start
  that download, but it cannot read the response; the bytes land on your own disk.
- Access tokens are audience-bound to the requested `resource`; tokens and codes
  are stored only as SHA-256 hashes, and API tokens are held in `data/auth/`,
  which is never web-readable and never part of a backup.
- Secrets are never logged; error messages are also scrubbed in the admin UI
  before they reach the log.
- All responses carry security headers; the admin pages use a `script-src 'self'`
  CSP, every other route keeps `default-src 'none'`.
- The consent page always requires a password, so it is never click-through-only.
- The OAuth token and consent endpoints are rate-limited per IP (file-backed,
  no daemon); disable with `RATE_LIMIT_ENABLED=false`.
- `.env` and other dotfiles/config are denied by the root `.htaccess`, and the
  preferred deployment keeps them outside `public_html/` entirely.

## Limitations

- Keyword/BM25 search — no embeddings or vector search.
- Public OAuth clients only (`none` + PKCE); no revocation endpoint. Revoking is
  done by disabling the account or changing its password, which invalidates its
  tokens at once.
- Accounts are single-owner: one instance per household/team, not a hosted
  multi-tenant service. There is no self-service sign-up (an admin creates
  accounts) and no per-account quotas.
- `mcp/sdk` is pre-1.0 (experimental); pin it in `composer.lock`.

## Troubleshooting

- **`composer install` says PHP version mismatch** — you ran Composer with an
  older PHP; use 8.2+, or pin `platform.php` to the host version.
- **Search shows `engine: direct`** — `pdo_sqlite` is not enabled; the app still
  works, searching files directly.
- **`/ui` returns `internal_error`** — check `data/logs/app.log`; usually
  `data/sessions/` or the account's `data/users/<username>/` not writable, or
  `vendor/` not uploaded.
- **`/ui` shows the setup page even though you set credentials in `.env`** — the
  instance has no accounts and those variables are seed material; seeding happens
  once, and a store that was deleted afterwards is *unowned* on purpose. Create
  the admin with `/ui/setup` (it needs `ADMIN_SETUP_TOKEN` if you set one).
- **An MCP client says the token is invalid after you changed your password** —
  expected: every token of that account is invalidated by the change. Reconnect
  (or re-run the consent flow) and it will work.
- **A user's memory seems to be missing** — check
  `data/users/<username>/memory`; `data/users/.trash/<stamp>__<username>/` holds
  a deleted account's directory, and that account's `memory/.trash` holds its
  deleted entries.

See `AGENTS.md` for the project's core principles and constraints.
