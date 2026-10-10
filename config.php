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
// single/double quotes and `#` comments. Values already present in the real
// environment are NOT overwritten (phpdotenv "immutable" behaviour).
// ---------------------------------------------------------------------------
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

            if (getenv($__key) === false) {
                putenv($__key . '=' . $__value);
                $_ENV[$__key] = $__value;
            }
        }
    }
}
unset($__envFile, $__lines, $__line, $__key, $__value, $__len, $__first, $__last);

// Resolve the data directory once so derived defaults (index, logs, sessions)
// stay inside it even when DATA_PATH is overridden (e.g. tests, deployments).
$__dataPath = rtrim((string) ($_ENV['DATA_PATH'] ?? getenv('DATA_PATH') ?: __DIR__ . '/data'), '/\\');

return [
    // Public HTTPS base URL of the application, without trailing slash.
    // Example: https://memory.example.com
    'app_base_url' => rtrim((string) ($_ENV['APP_BASE_URL'] ?? getenv('APP_BASE_URL') ?: 'http://127.0.0.1:8080'), '/'),

    'app_env' => $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'development',

    'app_name' => 'MemoryDown',
    'app_version' => '1.0.0',

    // Absolute paths (defaults assume the repository layout).
    'data_path' => $__dataPath,
    'memory_path' => rtrim((string) ($_ENV['MEMORY_PATH'] ?? getenv('MEMORY_PATH') ?: $__dataPath . '/memory'), '/\\'),

    'log_path' => rtrim((string) ($_ENV['LOG_PATH'] ?? getenv('LOG_PATH') ?: $__dataPath . '/logs'), '/\\'),

    // OAuth: token lifetimes in seconds.
    'oauth_access_token_ttl' => (int) ($_ENV['OAUTH_ACCESS_TOKEN_TTL'] ?? getenv('OAUTH_ACCESS_TOKEN_TTL') ?: 3600),
    'oauth_refresh_token_ttl' => (int) ($_ENV['OAUTH_REFRESH_TOKEN_TTL'] ?? getenv('OAUTH_REFRESH_TOKEN_TTL') ?: 90 * 86400),
    'oauth_code_ttl' => (int) ($_ENV['OAUTH_CODE_TTL'] ?? getenv('OAUTH_CODE_TTL') ?: 600),

    // Optional: if set, the OAuth consent page requires this username.
    'oauth_username' => (string) ($_ENV['OAUTH_USERNAME'] ?? getenv('OAUTH_USERNAME') ?: ''),

    // Optional: if set, the OAuth consent page requires this password. May be
    // a plaintext value or a bcrypt hash (preferred, via password_hash()).
    'oauth_consent_password' => (string) ($_ENV['OAUTH_CONSENT_PASSWORD'] ?? getenv('OAUTH_CONSENT_PASSWORD') ?: ''),

    // Comma-separated allowlist of origins whose Client ID Metadata Documents
    // (CIMD) are accepted as a FALLBACK when a client presents a URL-formatted
    // client_id (not advertised in discovery; clients use DCR by default).
    'oauth_cimd_allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) ($_ENV['OAUTH_CIMD_ALLOWED_ORIGINS'] ?? getenv('OAUTH_CIMD_ALLOWED_ORIGINS') ?: 'chatgpt.com,localhost,127.0.0.1,::1'))
    ))),

    // MCP session lifetime in seconds.
    'mcp_session_ttl' => (int) ($_ENV['MCP_SESSION_TTL'] ?? getenv('MCP_SESSION_TTL') ?: 3600),

    // -----------------------------------------------------------------------
    // Web admin UI (/ui): browse, search and edit memories.
    // -----------------------------------------------------------------------
    'ui_enabled' => filter_var(
        $_ENV['UI_ENABLED'] ?? getenv('UI_ENABLED') ?: 'true',
        FILTER_VALIDATE_BOOL
    ),

    // bcrypt/argon hash of the admin password. Empty is allowed on first run:
    // /ui/setup stores a hash in data/auth/ui.json instead.
    'admin_password_hash' => (string) ($_ENV['ADMIN_PASSWORD_HASH'] ?? getenv('ADMIN_PASSWORD_HASH') ?: ''),

    // Optional extra secret required by the first-run /ui/setup form.
    'admin_setup_token' => (string) ($_ENV['ADMIN_SETUP_TOKEN'] ?? getenv('ADMIN_SETUP_TOKEN') ?: ''),

    // -----------------------------------------------------------------------
    // Full-text search index (disposable SQLite FTS5 cache over the Markdown).
    // -----------------------------------------------------------------------
    'index_enabled' => filter_var(
        $_ENV['INDEX_ENABLED'] ?? getenv('INDEX_ENABLED') ?: 'true',
        FILTER_VALIDATE_BOOL
    ),
    'index_path' => rtrim((string) ($_ENV['INDEX_PATH'] ?? getenv('INDEX_PATH') ?: $__dataPath . '/index/memory.sqlite'), '/\\'),

    // -----------------------------------------------------------------------
    // Public writings API (read-only): serves entries in the "writings"
    // category that carry `public: true`, as Markdown over HTTP. Disabled by
    // default. When enabled, PUBLIC_API_TOKEN must be set; requests must send
    // "Authorization: Bearer <token>".
    // -----------------------------------------------------------------------
    'public_api_enabled' => filter_var(
        $_ENV['PUBLIC_API_ENABLED'] ?? getenv('PUBLIC_API_ENABLED') ?: 'false',
        FILTER_VALIDATE_BOOL
    ),
    'public_api_token' => (string) ($_ENV['PUBLIC_API_TOKEN'] ?? getenv('PUBLIC_API_TOKEN') ?: ''),

    // -----------------------------------------------------------------------
    // Lightweight per-IP rate limiting for the OAuth token and consent
    // endpoints (fixed window, file-backed; no daemon required).
    // -----------------------------------------------------------------------
    'rate_limit_enabled' => filter_var(
        $_ENV['RATE_LIMIT_ENABLED'] ?? getenv('RATE_LIMIT_ENABLED') ?: 'true',
        FILTER_VALIDATE_BOOL
    ),
    'rate_limit_token_max' => (int) ($_ENV['RATE_LIMIT_TOKEN_MAX'] ?? getenv('RATE_LIMIT_TOKEN_MAX') ?: 30),
    'rate_limit_consent_max' => (int) ($_ENV['RATE_LIMIT_CONSENT_MAX'] ?? getenv('RATE_LIMIT_CONSENT_MAX') ?: 10),
    'rate_limit_window' => (int) ($_ENV['RATE_LIMIT_WINDOW'] ?? getenv('RATE_LIMIT_WINDOW') ?: 60),
];
