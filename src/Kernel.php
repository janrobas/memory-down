<?php

declare(strict_types=1);

namespace MemoryDown;

use MemoryDown\Support\FileLogger;

/**
 * Application wiring: builds every service from the config array.
 *
 * The layers stay separate: HTTP routing (Http\App), OAuth (Auth), memory
 * (Memory) and Markdown storage (Memory\MemoryStore) do not depend on each
 * other's request handling. MCP tool closures call the memory layer directly.
 *
 * This is the *instance* container only. Everything here is shared by every
 * account — configuration, logging, the OAuth clients and tokens, rate
 * limiting — and nothing here can read anybody's memory. Per-user services
 * (their store, their search index) are built on demand by
 * {@see Users\UserServicesFactory}, which is the only way to reach a corpus and
 * therefore the only place a user boundary could be crossed by accident.
 */
final class Kernel
{
    public readonly Config $config;
    public readonly FileLogger $logger;

    /** The account store: who exists, and what proves it. */
    public readonly Users\UserStore $users;
    public readonly Users\UserPaths $userPaths;
    public readonly Users\UserServicesFactory $userServices;
    public readonly Users\UserDirectories $userDirectories;

    public readonly Auth\TokenStore $tokens;
    public readonly Auth\ClientRegistry $clients;
    public readonly Auth\AuthorizationServer $auth;
    public readonly Auth\McpGate $mcpGate;
    public readonly Support\RateLimiter $rateLimiter;

    private static ?self $instance = null;

    public static function boot(array $config): self
    {
        return self::$instance ??= new self(new Config($config));
    }

    public static function get(): self
    {
        if (null === self::$instance) {
            throw new \RuntimeException('Kernel not booted.');
        }

        return self::$instance;
    }

    private function __construct(Config $config)
    {
        $this->config = $config;

        $dirs = [
            'data' => $config->dataPath,
            'users' => $config->usersPath,
            'auth' => $config->dataPath . '/auth',
            'sessions' => $config->dataPath . '/sessions',
            'mcp_sessions' => $config->mcpSessionsPath,
            'logs' => $config->logPath,
        ];
        foreach ($dirs as $name => $path) {
            if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
                throw new \RuntimeException("Cannot create {$name} directory: {$path}");
            }
        }

        $this->logger = new FileLogger($config->logPath . '/app.log', $config->appName);

        $this->users = Users\UserStore::at($config->dataPath . '/auth');
        $this->userPaths = new Users\UserPaths($config->usersPath);
        $this->userDirectories = new Users\UserDirectories($this->userPaths, $this->logger);
        $this->userServices = new Users\UserServicesFactory($this->userPaths, $config->indexEnabled, $this->logger);

        // Before anything can serve a request: give a pre-accounts instance its
        // first account, and bring its memory along. Idempotent, and a no-op on
        // an instance that already has accounts (or has none to seed).
        (new Users\Migration(
            $config,
            $this->users,
            $this->userPaths,
            $this->userDirectories,
            $this->logger,
        ))->run();

        $this->tokens = new Auth\TokenStore(
            $config->dataPath . '/auth',
            $config->oauthAccessTokenTtl,
            $config->oauthRefreshTokenTtl,
            $config->oauthCodeTtl,
        );

        $this->clients = new Auth\ClientRegistry(
            $config->dataPath . '/auth',
            $config->oauthCimdAllowedOrigins,
            $this->logger,
        );

        $this->rateLimiter = new Support\RateLimiter(
            $config->dataPath . '/auth/ratelimit',
            $config->rateLimitEnabled,
        );

        $this->auth = new Auth\AuthorizationServer(
            $config,
            $this->users,
            $this->tokens,
            $this->clients,
            $this->logger,
            $this->rateLimiter,
        );

        $this->mcpGate = new Auth\McpGate(
            $this->tokens,
            $this->users,
            $config,
            $this->logger,
        );
    }
}
