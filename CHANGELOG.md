# Changelog

All notable changes to MemoryDown are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
version lives in `config.php` (`app_version`).

## Releasing

1. Move the `[Unreleased]` entries under a new version heading, bump
   `app_version` in `config.php`, and commit both together.
2. Tag that commit: `git tag -a v<version> -m "MemoryDown <version>"`.
3. Push with tags: `git push --follow-tags`.

## [Unreleased]

### Added
- RFC 7009 token revocation endpoint (`POST /oauth/revoke`), advertised in the
  authorization-server metadata; the response is always 200 so it never acts
  as a validity oracle.
- OAuth 2.1 refresh-token reuse detection: presenting an already-rotated
  refresh token revokes its whole family. The reply stays a plain
  `invalid_grant`.
- Per-IP rate limiting for the admin UI sign-in (`RATE_LIMIT_LOGIN_MAX`) and
  first-run setup (`RATE_LIMIT_SETUP_MAX`) forms, next to the existing OAuth
  limits.
- Opt-in `Strict-Transport-Security` header (`HSTS_ENABLED`, default off).
- Automatic log rotation for `data/logs/app.log` (`LOG_MAX_BYTES`, one `.1`
  file kept).
- `/health` now reports `log_bytes` and `disk_free_bytes`.
- Dependabot configuration for Composer and GitHub Actions.

### Changed
- The admin UI shows the running version (`app_version`) next to the
  MemoryDown brand, in smaller muted letters.

## [1.0.0]

Everything up to and including the multi-account release.

### Added
- MCP server over Streamable HTTP at `/mcp` (and the base URL), supporting
  both the `2025-11-25` handshake era and the stateless `2026-07-28` revision.
- OAuth 2.1 authorization server: authorization-code + PKCE (S256),
  refresh-token rotation, RFC 9207 issuer identification, DCR (RFC 7591),
  RFC 9728 protected-resource metadata and RFC 8414 authorization-server
  metadata.
- Seven MCP tools: `remember`, `recall`, `search_memory`, `read_memory`,
  `update_memory`, `forget_memory`, `list_memory`. `writings` is read-only
  through MCP.
- Accounts: one directory per account, one credential per person, `admin` /
  `user` roles, credential-epoch revocation, recoverable account deletion.
- Admin UI at `/ui` with a Markdown editor, live search, tags, archiving,
  trash/restore and one-click backup downloads.
- Disposable per-account SQLite FTS5 search index with automatic direct-file
  fallback.
- Read-only, token-gated public writings API (`/public/writings`).
- Read-only, token-gated workflows API (`/api/workflows`).
- Workflow definitions in the `workflows` category with the human-only
  `executable` tag and the `dry-run` / `apply` / `yolo` mode model.
- Diagnostics at `/health`, `/health/mcp` and `/health/oauth`.
- Rate limiting for the OAuth token and consent endpoints.

### Security
- Strict path whitelisting against traversal; atomic Markdown writes;
  hashed token storage; one-way password hashes; CSRF protection on every
  state-changing admin route; security headers and CSP on all responses.
