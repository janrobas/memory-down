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
- **Category names are translated in the interface — and only there.** The twelve
  built-in categories read "Nastavitve", "Zapiski", "Besedila" in Slovenian
  ("Preferenze", "Note", "Pubblicazioni" in Italian), in the sidebar, the chips,
  the *Move to* picker, the breadcrumb and the trash list. The identity does not
  move: the directory name, `?category=` in URLs, the `category` argument of
  `/ui/api/*`, every `data-*` attribute the editor compares against (drop
  targets, the reserved `executable` rule, `public` being a `writings` flag), the
  MCP tools, the workflows API and the backup keep the slug — `I18n\Categories`
  only ever produces text, and a category with no label (a folder added by hand)
  is shown as it is stored. The sidebar keeps its order by slug, so switching
  language never shuffles the list, and "moved to" reads "Premaknjeno v: Ideje"
  so no label has to be declined.
- **The admin UI speaks Slovenian, English and Italian.** The chooser sits in the
  ⋯ menu (and on the sign-in page, the OAuth consent page and `/about`, since
  those come before any preference exists), and the choice is remembered in one
  `memorydown_lang` cookie per browser — resolution order: cookie,
  `Accept-Language`, then the new `APP_DEFAULT_LOCALE` (default `sl`). It is UI
  only: memory, tags, categories and stored workflow definitions are never
  translated or rewritten, the MCP tools stay English, and `/health` and the API
  payloads are unchanged. `GET /ui/language?lang=…&back=…` is a plain link — one
  cookie and a redirect, no JavaScript, no CSRF token — and it only ever returns
  to a path inside this instance, so it cannot be used as an open redirector.
  Plurals are real plurals (Slovenian has four forms: `1 vnos`, `2 vnosa`,
  `5 vnosov`). Catalogues are plain PHP arrays (`src/I18n/lang/{sl,en,it}.php`)
  rather than gettext, because a compiled locale is exactly what cheap shared
  hosting tends to be missing.
- The **workflow definition verdict** is translated too: the parser now returns
  problem *codes* and their parameters instead of English sentences, and the UI
  renders them in the reader's language, so a refused definition says the same
  thing in all three.
- **Login and consent refusals** carry codes rather than sentences
  (`WebAuth::attempt()` returns `locked` / `credentials` / `disabled`), so the
  same refusal reads correctly in every language.
- Unit tests check that the three catalogues have identical keys, identical
  `{placeholders}` and the plural forms their language needs, that every workflow
  verdict code is translated, and that the codes used by `WorkflowDefinition` and
  app.js all exist; the protocol suite drives the UI in each language, the chooser
  endpoint (including crafted `back` values) and the translated consent page.

## [1.0.2]

### Added
- **Private entries.** Any memory can be marked *Private* in the editor (a
  checkbox beside Archived/Public) and is then invisible to every AI-facing
  reader: `search_memory`, `recall`, `list_memory`, `read_memory`,
  `update_memory` and `forget_memory` never see it — on `/mcp` and on the admin
  connector `/mcp/all` alike — and `/public/writings` and `/api/workflows`
  withhold it too. Only the human can set or lift it; the MCP tools have no field
  for it and refuse a private entry by id. The store and both search engines
  exclude private entries by default (`includePrivate`), so forgetting to think
  about it hides rather than leaks; the UI shows them with a `private` badge,
  finds them with `is:private`, and offers the switch on every entry. `private` +
  `public` and `private` + the reserved `executable` tag are refused with a
  reason. Not encryption: the Markdown file stays readable in Obsidian, the
  backup and over FTP, and the UI says so.
- An **admin MCP connector** at `/mcp/all`: the same seven tools, over every
  enabled account at once. Each tool gains an `owner` argument — reads search one
  account or all of them, writes go to `owner` (default: your own account) — and
  every result says which account it came from. It is its own OAuth audience with
  its own RFC 9728 metadata
  (`/.well-known/oauth-protected-resource/mcp/all`), so a token minted for `/mcp`
  is refused there and vice versa; a non-admin's valid token gets `403` (no
  challenge, so it is not sent back into OAuth). Disabled accounts are invisible to
  it, a cross-account delete lands in that account's own trash, and the per-account
  protections (`writings` read-only, the reserved `executable` tag) apply unchanged
  because the same handlers run. The ordinary `/mcp` connector never exposes
  `owner` at all. `/ui/settings` shows both connector URLs (`AGENTS.md`,
  "Amendment: the admin MCP connector").
- Workflow definitions understand the optional runner fields `run_if`
  (`unread_mail` | `unread_mail_any`), `mail_context` (`unread`), `mailbox` and
  `mail_mark_read` (a boolean, default `false`),
  so an executable definition that uses them is no longer reported as carrying
  unknown fields. They are conditions/context only: the parse stays advisory,
  they can skip a run, preload unread mail or mark it read, and none of them
  grants a tool or changes `mode` (`docs/WORKFLOWS.md` §4, §8).
- Search results and `list_memory` entries carry `chars`, the size of the body
  they omit, so an agent can decide whether reading an entry is worth it.

### Changed
- In the admin UI the filename now follows the title: saving a renamed entry
  moves the `.md` file to the new title's slug and keeps the six-hex suffix
  (`stari-naslov-a1b2c3.md` → `novi-naslov-a1b2c3.md`), with the frontmatter `id`
  rewritten to match, so the sidebar, breadcrumbs, `list_memory` and
  `/api/workflows` no longer show a stale name. The save response carries the new
  id and the editor adopts it, so in-progress editing continues without a reload.
  Deliberately narrow: MCP never renames (`update_memory` keeps an agent's id
  stable), a `public: true` writing keeps its id because that id is its public
  URL, filenames not generated by this app are never touched, an empty title
  never renames, and a taken target name gets a fresh suffix instead of
  overwriting the other entry. The search index needs no special handling: its
  signature includes each file's relative path, so a rename triggers the usual
  lazy rebuild.
- A plain visit to the site root (`GET /`) now redirects to the admin UI at
  `/ui`, which shows the sign-in page, the one-time setup, or the editor
  depending on the session. The endpoint list that used to live at `/` moved to
  `/about`, linked from the bottom of the sign-in page; with `UI_ENABLED=false`
  the root keeps showing that list, because there is no UI to open. `POST /` and
  `GET /` with `Accept: text/event-stream` are still the MCP endpoint, so
  clients that probe the base URL instead of `/mcp` are unaffected.
- Search matches text diacritic-insensitively even when the SQLite index is
  unavailable: the direct Markdown scan folds č/š/ž/ć/đ (and the rest of the
  Latin-1/Latin Extended-A letters) through the new `MemoryDown\Memory\Text`,
  which `Slug` now shares instead of keeping its own table. "posta" finds
  "pošta" and vice versa. The snippet still shows the author's spelling.
- A search snippet is taken around the first query term the entry actually
  contains, instead of always around the query's first term — it was returning
  the opening of the entry whenever that term was absent, or whenever the match
  existed only through folding.
- Equal search scores are ordered newest first (by `updated`), matching
  `MemoryStore::list()`, so two versions of the same text no longer come back in
  filesystem order.

### Fixed
- `list_memory` no longer returns entry bodies. Its description always promised
  metadata only; the bodies were removed and replaced by `chars`. `recall` keeps
  returning bodies, as documented.
- A checkbox toggled while a save was in flight (Archived, Public, Private) could
  be recorded as saved without ever reaching the server: the editor marked the
  *live* form state as saved instead of the payload it had posted. It now
  snapshots exactly what was sent — with the id the server assigned after a
  rename — so a late click stays dirty and is saved.

## [1.0.1]

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
