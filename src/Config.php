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
    public readonly string $memoryPath;
    public readonly string $logPath;

    public readonly int $oauthAccessTokenTtl;
    public readonly int $oauthRefreshTokenTtl;
    public readonly int $oauthCodeTtl;
    public readonly string $oauthUsername;
    public readonly string $oauthConsentPassword;
    /** @var list<string> */
    public readonly array $oauthCimdAllowedOrigins;

    public readonly int $mcpSessionTtl;

    public readonly bool $uiEnabled;
    public readonly string $adminPasswordHash;
    public readonly string $adminSetupToken;

    public readonly bool $indexEnabled;
    public readonly string $indexPath;

    public function __construct(array $c)
    {
        $this->appBaseUrl = (string) $c['app_base_url'];
        $this->appEnv = (string) $c['app_env'];
        $this->appName = (string) $c['app_name'];
        $this->appVersion = (string) $c['app_version'];
        $this->dataPath = (string) $c['data_path'];
        $this->memoryPath = (string) $c['memory_path'];
        $this->logPath = (string) $c['log_path'];
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
        $this->indexPath = (string) ($c['index_path'] ?? $this->dataPath . '/index/memory.sqlite');
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
