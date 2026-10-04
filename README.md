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
- **Six MCP tools:** `remember`, `recall`, `search_memory`, `update_memory`,
  `forget_memory`, `list_memory`.
- **Admin UI** at `/ui`: a two-pane browser/editor (search on the left,
  Markdown editor + live preview on the right) protected by a password. Themes,
  autosave, drag-to-move between categories. An **All / Active / Archived**
  filter, a per-memory **Archived** checkbox, and `tag:foo` search.
- **Search:** a disposable SQLite FTS5 index (BM25), rebuilt from the Markdown
  on demand, with automatic fallback to a direct file scan. Archived memories
  stay indexed and searchable but rank after active ones.
- **Diagnostics** at `/health`, `/health/mcp`, `/health/oauth`.
- **Filesystem-safe:** strict path whitelisting prevents traversal.

## Requirements

- **PHP 8.2+**. Note the bundled `vendor/` needs **8.4.1+**; for an older host,
  rebuild dependencies pinned to its version (see Install).
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
- **Delete** appears next to the title for existing memories.

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

## Search index

Search uses a **disposable SQLite FTS5 index** (`INDEX_PATH`). Markdown stays the
source of truth; the index is a cache. It rebuilds automatically when the corpus
changes, can be rebuilt via `POST /ui/reindex`, and falls back to a direct
`.md` scan when `pdo_sqlite`/FTS5 is unavailable. Deleting it loses nothing.
Every `.md` file is indexed, including archived memories (archiving sets an
`archived: true` frontmatter key; the file is never moved or hidden).

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
