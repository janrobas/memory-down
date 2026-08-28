# MemoryDown

A tiny personal AI memory server written in plain PHP. It gives ChatGPT and
other MCP clients persistent memory, stored as **Markdown files**, over MCP
with OAuth 2.1.

> **Disclaimer:** This project was vibe-coded with AI assistance. It works for
> its author's single-user use case, but review the code before relying on it
> for anything important.

```
ChatGPT / OpenCode / other MCP clients
                ↓
           MCP over HTTPS
                ↓
              OAuth 2.1
                ↓
            MemoryDown
                ↓
          Markdown files
```

Designed for cheap PHP shared hosting: no VPS, no Docker, no Node, no Python,
no Redis, no MySQL/PostgreSQL, no database, no long-running processes.
Deployable via FTP.

---

## Features

- **Markdown is the source of truth.** Every memory is a `.md` file with a
  small frontmatter block, portable to Obsidian / VS Code / Codex.
- **MCP over Streamable HTTP**, serving *both* protocol eras from one endpoint:
  the `initialize` handshake (through `2025-11-25`) and the stateless
  `2026-07-28` revision.
- **OAuth 2.1** authorization code flow with PKCE (S256), refresh-token
  rotation, RFC 9207 issuer identification, dynamic client registration (DCR),
  RFC 9728 protected-resource metadata and RFC 8414 authorization-server
  metadata.
- **Six MCP tools:** `remember`, `recall`, `search_memory`, `update_memory`,
  `forget_memory`, `list_memory`.
- **Diagnostics** at `/health`, `/health/mcp`, `/health/oauth`.
- **Filesystem-safe**: strict path whitelisting prevents traversal.

## Requirements

- PHP 8.2+ (8.3+ recommended)
- PHP extensions: `fileinfo`, `mbstring`, `openssl` (plus `curl` recommended)
- Composer (locally, to install dependencies before uploading)

## Project structure

```
public_html/
    index.php        front controller
    .htaccess        routes everything to index.php
src/
    Http/App.php     routing + diagnostics
    Auth/            OAuth authorization server, token store, DCR, bearer middleware
    Memory/          Markdown storage, search, path validation
    Mcp/             MCP server (official mcp/sdk) + tool definitions
    Support/         logger, atomic JSON store
config.php           environment-driven configuration
.env.example         configuration template (copy to .env)
data/
    memory/          THE memory store (Markdown, portable)
    auth/            OAuth codes/tokens/registered clients (generated)
    sessions/        MCP sessions (generated)
    logs/            request logs (generated)
tests/run-tests.php  protocol-chain integration test
composer.json
```

## Install & run locally

```bash
composer install
php -S 127.0.0.1:8080 -t public_html public_html/index.php
```

Open http://127.0.0.1:8080/health.

## Configuration

Configuration is read in this order of precedence: **real environment
variables → `.env` file → built-in defaults**. For a typical FTP deployment,
copy `.env.example` to `.env`, fill it in, and upload it with the rest of the
project (it is git-ignored). Values set in the hosting panel or php.ini take
precedence over `.env`. No `.env` parser dependency is required — it is a
built-in, dependency-free loader.

| Variable | Default | Purpose |
| --- | --- | --- |
| `APP_BASE_URL` | `http://127.0.0.1:8080` | Public HTTPS base URL, no trailing slash |
| `APP_ENV` | `development` | `production` / `development` |
| `DATA_PATH` | `./data` | Runtime data (auth, sessions) |
| `MEMORY_PATH` | `./data/memory` | Where Markdown memory lives |
| `LOG_PATH` | `./data/logs` | Request log directory |
| `OAUTH_USERNAME` | *(empty)* | If set, the consent page requires this username. |
| `OAUTH_CONSENT_PASSWORD` | *(empty)* | Consent-page password: a plaintext value or a bcrypt hash (recommended; `password_hash()`). |
| `OAUTH_CIMD_ALLOWED_ORIGINS` | `chatgpt.com,localhost,127.0.0.1,::1` | Origins accepted as a CIMD fallback (not advertised) |
| `OAUTH_ACCESS_TOKEN_TTL` | `3600` | Access token lifetime (seconds) |
| `OAUTH_REFRESH_TOKEN_TTL` | `7776000` | Refresh token lifetime (seconds) |
| `OAUTH_CODE_TTL` | `600` | Authorization code lifetime (seconds) |

Example:

```
APP_BASE_URL=https://memory.example.com
APP_ENV=production
MEMORY_PATH=/absolute/path/to/data/memory
OAUTH_CONSENT_PASSWORD=a-long-random-password
```

## Deployment (shared hosting, FTP)

1. Run `composer install --no-dev` locally.
2. Upload everything (including `vendor/`) via FTP.
3. Point the domain/subdomain **document root at `public_html/`**.
4. Make `data/` (and `data/memory`, `data/auth`, `data/sessions`, `data/logs`)
   writable by PHP.
5. Copy `.env.example` to `.env`, fill it in, and upload it — or set the same
   values in the hosting panel / php.ini.
6. Verify `https://your-domain/health` returns `{"status":"ok",...}`.
7. Test OAuth discovery (below).
8. Connect ChatGPT (below).

If you cannot change the document root, use the safety-net `.htaccess` at the
project root that denies everything except `public_html/`, and point the domain at
the project root instead.

## OAuth setup

MemoryDown is its own OAuth 2.1 authorization server (single user). Endpoints:

| Endpoint | Purpose |
| --- | --- |
| `/.well-known/oauth-protected-resource` and `.../mcp` | RFC 9728 resource metadata |
| `/.well-known/oauth-authorization-server` and `/.well-known/openid-configuration` | RFC 8414 / OIDC discovery |
| `/oauth/authorize` | Consent page |
| `/oauth/token` | Token endpoint |
| `/oauth/register` | Dynamic client registration (RFC 7591) |

Client identification uses **Dynamic Client Registration (DCR, RFC 7591)** —
the same approach as a typical single-user MCP server. Every client (ChatGPT,
MCP Inspector, Claude, OpenCode, …) registers itself at `/oauth/register` and
then runs the authorization-code + PKCE flow. No outbound network calls are
needed at runtime.

CIMD (Client ID Metadata Documents) is **not advertised** in discovery, so
clients default to DCR. A URL-formatted `client_id` is still accepted as a
fallback for allow-listed origins (`chatgpt.com` and loopback by default).

V1 supports **public clients only** (`token_endpoint_auth_method: "none"` with
PKCE). Signed `private_key_jwt` client assertions are not yet implemented.

## Connect ChatGPT

1. In ChatGPT, enable **developer mode** (Settings → Security and login).
2. Go to **ChatGPT → Connectors** (or Plugins) and create a new connection.
3. Enter your server URL, e.g. `https://memory.example.com/mcp`.
4. Choose **OAuth** authentication.
5. ChatGPT will discover your metadata, open the consent page, and after you
   approve it will exchange a code for tokens and start calling tools.

Because MemoryDown serves RFC 9728 metadata at both the root and `/mcp`, and
answers MCP at both `/` and `/mcp`, the connection works whether ChatGPT probes
the base URL or the `/mcp` path.

## Test manually

Health and discovery (no auth):

```bash
curl -s https://memory.example.com/health
curl -s https://memory.example.com/.well-known/oauth-protected-resource
curl -s https://memory.example.com/.well-known/oauth-authorization-server
```

Unauthenticated MCP request should return `401` with a challenge:

```bash
curl -si https://memory.example.com/mcp \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"curl","version":"1.0"}}}'
# -> HTTP/1.1 401 Unauthorized
#    WWW-Authenticate: Bearer resource_metadata="https://.../.well-known/oauth-protected-resource/mcp", scope="memory:all"
```

Full protocol-chain test (spawns an isolated server and walks health →
discovery → 401 → DCR → consent → token → refresh → MCP both eras → tool calls
→ traversal attacks):

```bash
php tests/run-tests.php
```

To test with the official MCP Inspector (needs Node), point it at your server
URL with OAuth enabled; it will DCR-register and drive the same flow.

## Testing the memory tools

After connecting, ChatGPT (or the Inspector) can call the tools. `remember`
writes a file like:

```markdown
---
type: preferences
tags:
  - notes
  - markdown
created: 2026-08-28
updated: 2026-08-28
source: chatgpt
id: notes-system-a1b2c3
---

# Notes system

User prefers Markdown-based notes.
```

## Security notes

- Memory paths are strictly whitelisted (categories and ids by regex, plus a
  canonical-path containment check) — `../`, encoded traversal, absolute paths
  and deletion outside the memory root are rejected.
- Access tokens are audience-bound to the `resource` that was requested during
  OAuth; only this server's two canonical resources are accepted.
- Tokens and authorization codes are stored only as SHA-256 hashes.
- CIMD (used only as a fallback for URL client_ids) is HTTPS-only,
  origin-allowlisted and SSRF-guarded.
- Secrets are never logged: the logger only records messages and safe scalars;
  never passwords, tokens, codes or client secrets. Error messages are scrubbed
  of the consent password before being logged.
- All responses carry security headers (`X-Content-Type-Options`,
  `X-Frame-Options`, `Referrer-Policy`, a restrictive `Content-Security-Policy`).
- The MCP endpoint deliberately does **not** use Origin/Host allow-listing
  (DNS-rebinding protection): ChatGPT's connector may send an `Origin` header,
  and strict allow-listing would reject it with `403`. The endpoint is already
  gated by OAuth bearer tokens and served behind a web server that validates
  `Host`.
- Set `OAUTH_CONSENT_PASSWORD` (ideally a bcrypt hash) so the consent page is
  not click-through-only.

## Limitations / uncertainties (V1)

- Public OAuth clients only (`none` + PKCE); `private_key_jwt` is not
  implemented, so ChatGPT will use the public-client flow.
- No revocation endpoint; refresh tokens are rotated on use and expire.
- Consent uses a single username + password (or just a password, optionally a
  bcrypt hash) rather than a full login with sessions and rate limiting. This
  is a deliberate simplification for a single-user personal server.
- CIMD is not advertised; it is only a fallback for allow-listed origins. If a
  client presents a `chatgpt.com` client_id, its document is fetched at runtime
  (needs outbound HTTPS from the host).
- The `mcp/sdk` is official but pre-1.0 (experimental). Pin the version in
  `composer.lock`.
- mTLS client-certificate verification of ChatGPT is not implemented (not
  required to connect; shared hosting usually cannot do it).
- Full-text search is simple (substring/token ranking), no embeddings — by
  design for V1.

## AGENTS.md

See `AGENTS.md` for the project's core principles and constraints (Markdown as
source of truth, shared-hosting limits, no framework, memory semantics, etc.).
