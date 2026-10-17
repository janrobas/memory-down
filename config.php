<?php

declare(strict_types=1);

/**
 * MemoryDown configuration.
 *
 * Values are read, in order of precedence:
 *   1. real process environment variables (hosting panel, php.ini, shell)
 *   2. a `.env` file in the project root (see `.env.example`)
 *   3. built-in defaults below
 *
 * Nothing secret is committed to the repository (`.env` is git-ignored).
 */

// ---------------------------------------------------------------------------
// Minimal, dependency-free `.env` loader. Lines are KEY=VALUE with optional
// single/double quotes and `#` comments.
//
// Values are held in a local array and are deliberately NEVER written into the
// process environment (no `putenv()`, no `$_ENV` mutation). Real process
// environment variables therefore still take precedence, while the `.env` file
// is re-read on every request so that edits take effect immediately.
//
// This matters on PHP-FPM (shared hosting): `putenv()` changes persist across
// requests within a worker process, so writing `.env` values into the process
// environment would make the first request's values "stick" and later edits to
// `.env` be ignored until the worker/process pool is recycled.
// ---------------------------------------------------------------------------
$__dotenv = [];
$__envFile = __DIR__ . '/.env';
if (is_file($__envFile)) {
    $__lines = @file($__envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($__lines)) {
        foreach ($__lines as $__line) {
            $__line = trim($__line);
            if ('' === $__line || '#' === $__line[0]) {
                continue;
            }

            [$__key, $__value] = array_pad(explode('=', $__line, 2), 2, '');
            $__key = trim($__key);
            $__value = trim($__value);
            if ('' === $__key) {
                continue;
            }

            $__len = strlen($__value);
            if ($__len >= 2) {
                $__first = $__value[0];
                $__last = $__value[$__len - 1];
                if (('"' === $__first && '"' === $__last) || ("'" === $__first && "'" === $__last)) {
                    $__value = substr($__value, 1, -1);
                }
            }

            $__dotenv[$__key] = $__value;
        }
    }
}

// Resolve a configuration value: real environment wins, then `.env`, then the
// provided default. Empty values are treated as unset so a blank line in
// `.env` (e.g. "PUBLIC_API_TOKEN=") falls back to the default.
$__env = static function (string $key, string $default = '') use ($__dotenv): string {
    $value = getenv($key);
    if (false === $value) {
        $value = $__dotenv[$key] ?? false;
    }

    return (false === $value || '' === $value) ? $default : $value;
};

unset($__envFile, $__lines, $__line, $__key, $__value, $__len, $__first, $__last, $__dotenv);

// Resolve the data directory once so derived defaults (index, logs, sessions)
// stay inside it even when DATA_PATH is overridden (e.g. tests, deployments).
$__dataPath = rtrim($__env('DATA_PATH', __DIR__ . '/data'), '/\\');

return [
    // Public HTTPS base URL of the application, without trailing slash.
    // Example: https://memory.example.com
    'app_base_url' => rtrim($__env('APP_BASE_URL', 'http://127.0.0.1:8080'), '/'),

    'app_env' => $__env('APP_ENV', 'development'),

    'app_name' => 'MemoryDown',
    'app_version' => '1.0.0',

    // Absolute paths (defaults assume the repository layout).
    'data_path' => $__dataPath,

    // Where each account's data lives: DATA_PATH/users/<id>/.
    'users_path' => rtrim($__env('USERS_PATH', $__dataPath . '/users'), '/\\'),

    // Deprecated: the single-user memory directory of a pre-accounts instance.
    // Read once by the migration that moves its corpus under the first account.
    'memory_path' => rtrim($__env('MEMORY_PATH', $__dataPath . '/memory'), '/\\'),

    'log_path' => rtrim($__env('LOG_PATH', $__dataPath . '/logs'), '/\\'),

    // Per-user MCP transport sessions: DATA_PATH/sessions/mcp/<id>/.
    'mcp_sessions_path' => rtrim($__env('MCP_SESSIONS_PATH', $__dataPath . '/sessions/mcp'), '/\\'),

    // OAuth: token lifetimes in seconds.
    'oauth_access_token_ttl' => (int) $__env('OAUTH_ACCESS_TOKEN_TTL', '3600'),
    'oauth_refresh_token_ttl' => (int) $__env('OAUTH_REFRESH_TOKEN_TTL', (string) (90 * 86400)),
    'oauth_code_ttl' => (int) $__env('OAUTH_CODE_TTL', '600'),

    // Accounts live in data/auth/users.json, created in the admin UI. These are
    // SEED MATERIAL for the very first admin only, used when no account exists
    // yet: a single-user instance being upgraded is migrated from them, and on a
    // fresh install /ui/setup creates the first admin instead. Once an account
    // exists they are ignored, so changing your password in the UI sticks.

    // Deprecated: becomes the first admin's username (default: "admin").
    'oauth_username' => $__env('OAUTH_USERNAME'),

    // Deprecated: becomes the first admin's password. May be a plaintext value
    // or a bcrypt hash (preferred, via password_hash()).
    'oauth_consent_password' => $__env('OAUTH_CONSENT_PASSWORD'),

    // Comma-separated allowlist of origins whose Client ID Metadata Documents
    // (CIMD) are accepted as a FALLBACK when a client presents a URL-formatted
    // client_id (not advertised in discovery; clients use DCR by default).
    'oauth_cimd_allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', $__env('OAUTH_CIMD_ALLOWED_ORIGINS', 'chatgpt.com,localhost,127.0.0.1,::1'))
    ))),

    // MCP session lifetime in seconds.
    'mcp_session_ttl' => (int) $__env('MCP_SESSION_TTL', '3600'),

    // -----------------------------------------------------------------------
    // Web admin UI (/ui): browse, search and edit memories.
    // -----------------------------------------------------------------------
    'ui_enabled' => filter_var(
        $__env('UI_ENABLED', 'true'),
        FILTER_VALIDATE_BOOL
    ),

    // bcrypt/argon hash of the first admin's password (deprecated seed
    // material; /ui/setup stores it in data/auth/users.json otherwise).
    'admin_password_hash' => $__env('ADMIN_PASSWORD_HASH'),

    // Optional extra secret required by the first-run /ui/setup form.
    'admin_setup_token' => $__env('ADMIN_SETUP_TOKEN'),

    // -----------------------------------------------------------------------
    // Full-text search index (disposable SQLite FTS5 cache over the Markdown).
    // One index per account, inside that account's directory
    // (DATA_PATH/users/<id>/index/memory.sqlite); deleting it loses nothing.
    // -----------------------------------------------------------------------
    'index_enabled' => filter_var(
        $__env('INDEX_ENABLED', 'true'),
        FILTER_VALIDATE_BOOL
    ),

    // -----------------------------------------------------------------------
    // Public writings API (read-only): serves entries in the "writings"
    // category that carry `public: true`, as Markdown over HTTP. Disabled by
    // default. Each account has its own token (set in the admin UI under
    // Settings); requests must send "Authorization: Bearer <token>", and the
    // token decides whose writings are served.
    // -----------------------------------------------------------------------
    'public_api_enabled' => filter_var(
        $__env('PUBLIC_API_ENABLED', 'false'),
        FILTER_VALIDATE_BOOL
    ),

    // -----------------------------------------------------------------------
    // Workflows API (read-only): serves the "workflows" category — the
    // definitions a runner may execute, plus the drafts and notes beside them —
    // as JSON, with the stored Markdown body. Disabled by default. Per-account
    // token, exactly like the writings API above.
    // -----------------------------------------------------------------------
    'workflows_api_enabled' => filter_var(
        $__env('WORKFLOWS_API_ENABLED', 'false'),
        FILTER_VALIDATE_BOOL
    ),

    // -----------------------------------------------------------------------
    // Lightweight per-IP rate limiting for the OAuth token and consent
    // endpoints (fixed window, file-backed; no daemon required).
    // -----------------------------------------------------------------------
    'rate_limit_enabled' => filter_var(
        $__env('RATE_LIMIT_ENABLED', 'true'),
        FILTER_VALIDATE_BOOL
    ),
    'rate_limit_token_max' => (int) $__env('RATE_LIMIT_TOKEN_MAX', '30'),
    'rate_limit_consent_max' => (int) $__env('RATE_LIMIT_CONSENT_MAX', '10'),
    'rate_limit_window' => (int) $__env('RATE_LIMIT_WINDOW', '60'),
];
