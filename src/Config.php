<?php

declare(strict_types=1);

namespace MemoryDown;

/**
 * Typed access to the config array returned by config.php.
 */
final class Config
{
    public readonly string $appBaseUrl;
    public readonly string $appEnv;
    public readonly string $appName;
    public readonly string $appVersion;

    public readonly string $dataPath;
    /**
     * Deprecated: the single-user memory directory this instance used before it
     * had accounts. Only ever read by the one-time migration, which moves its
     * corpus under the first account's root.
     */
    public readonly string $memoryPath;
    /** Where the per-user directories live: DATA_PATH/users. */
    public readonly string $usersPath;
    public readonly string $logPath;
    /** Where per-user MCP transport sessions live: DATA_PATH/sessions/mcp. */
    public readonly string $mcpSessionsPath;

    public readonly int $oauthAccessTokenTtl;
    public readonly int $oauthRefreshTokenTtl;
    public readonly int $oauthCodeTtl;
    /** Deprecated: seed material for the first account's username. */
    public readonly string $oauthUsername;
    /** Deprecated: seed material for the first account's password. */
    public readonly string $oauthConsentPassword;
    /** @var list<string> */
    public readonly array $oauthCimdAllowedOrigins;

    public readonly int $mcpSessionTtl;

    public readonly bool $uiEnabled;
    /** Deprecated: seed material for the first account's password hash. */
    public readonly string $adminPasswordHash;
    public readonly string $adminSetupToken;

    public readonly bool $indexEnabled;

    public readonly bool $publicApiEnabled;

    public readonly bool $workflowsApiEnabled;

    public readonly bool $rateLimitEnabled;
    public readonly int $rateLimitTokenMax;
    public readonly int $rateLimitConsentMax;
    public readonly int $rateLimitLoginMax;
    public readonly int $rateLimitSetupMax;
    public readonly int $rateLimitWindow;

    public readonly bool $hstsEnabled;

    public readonly int $logMaxBytes;

    public function __construct(array $c)
    {
        $this->appBaseUrl = (string) $c['app_base_url'];
        $this->appEnv = (string) $c['app_env'];
        $this->appName = (string) $c['app_name'];
        $this->appVersion = (string) $c['app_version'];
        $this->dataPath = (string) $c['data_path'];
        $this->memoryPath = (string) $c['memory_path'];
        $this->usersPath = (string) ($c['users_path'] ?? $this->dataPath . '/users');
        $this->logPath = (string) $c['log_path'];
        $this->mcpSessionsPath = (string) ($c['mcp_sessions_path'] ?? $this->dataPath . '/sessions/mcp');
        $this->oauthAccessTokenTtl = (int) $c['oauth_access_token_ttl'];
        $this->oauthRefreshTokenTtl = (int) $c['oauth_refresh_token_ttl'];
        $this->oauthCodeTtl = (int) $c['oauth_code_ttl'];
        $this->oauthUsername = (string) $c['oauth_username'];
        $this->oauthConsentPassword = (string) $c['oauth_consent_password'];
        $this->oauthCimdAllowedOrigins = $c['oauth_cimd_allowed_origins'];
        $this->mcpSessionTtl = (int) $c['mcp_session_ttl'];

        $this->uiEnabled = (bool) ($c['ui_enabled'] ?? true);
        $this->adminPasswordHash = (string) ($c['admin_password_hash'] ?? '');
        $this->adminSetupToken = (string) ($c['admin_setup_token'] ?? '');

        $this->indexEnabled = (bool) ($c['index_enabled'] ?? true);

        $this->publicApiEnabled = (bool) ($c['public_api_enabled'] ?? false);

        $this->workflowsApiEnabled = (bool) ($c['workflows_api_enabled'] ?? false);

        $this->rateLimitEnabled = (bool) ($c['rate_limit_enabled'] ?? true);
        $this->rateLimitTokenMax = (int) ($c['rate_limit_token_max'] ?? 30);
        $this->rateLimitConsentMax = (int) ($c['rate_limit_consent_max'] ?? 10);
        $this->rateLimitLoginMax = (int) ($c['rate_limit_login_max'] ?? 10);
        $this->rateLimitSetupMax = (int) ($c['rate_limit_setup_max'] ?? 5);
        $this->rateLimitWindow = (int) ($c['rate_limit_window'] ?? 60);

        $this->hstsEnabled = (bool) ($c['hsts_enabled'] ?? false);

        $this->logMaxBytes = (int) ($c['log_max_bytes'] ?? 5 * 1024 * 1024);
    }

    public function isProduction(): bool
    {
        return 'production' === $this->appEnv;
    }

    /**
     * Canonical resource identifier for the MCP endpoint mounted at $path
     * ("/mcp" or "/"). Always HTTPS in production, HTTP in development.
     */
    public function resourceForPath(string $path): string
    {
        $path = '/' . trim($path, '/');

        return '/mcp' === $path ? $this->appBaseUrl . '/mcp' : $this->appBaseUrl;
    }

    public function protectedResourceMetadataUrl(string $path): string
    {
        $path = '/' . trim($path, '/');

        return '/mcp' === $path
            ? $this->appBaseUrl . '/.well-known/oauth-protected-resource/mcp'
            : $this->appBaseUrl . '/.well-known/oauth-protected-resource';
    }
}
