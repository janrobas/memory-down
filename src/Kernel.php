<?php

declare(strict_types=1);

namespace MemoryDown;

use MemoryDown\Http\App;
use MemoryDown\Support\FileLogger;
use MemoryDown\Support\JsonStore;

/**
 * Application wiring: builds every service from the config array.
 *
 * The layers stay separate: HTTP routing (Http\App), OAuth (Auth), memory
 * (Memory) and Markdown storage (Memory\MemoryStore) do not depend on each
 * other's request handling. MCP tool closures call the memory layer directly.
 */
final class Kernel
{
    public readonly Config $config;
    public readonly FileLogger $logger;
    public readonly Memory\MemoryStore $memory;
    public readonly Memory\SearchEngine $search;
    public readonly Auth\TokenStore $tokens;
    public readonly Auth\ClientRegistry $clients;
    public readonly Auth\AuthorizationServer $auth;

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

        foreach (['data', 'memory', 'auth', 'sessions', 'logs'] as $dir) {
            $path = match ($dir) {
                'memory' => $config->memoryPath,
                'auth' => $config->dataPath . '/auth',
                'sessions' => $config->dataPath . '/sessions',
                'logs' => $config->logPath,
                default => $config->dataPath,
            };
            if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
                throw new \RuntimeException("Cannot create data directory: {$path}");
            }
        }

        $this->logger = new FileLogger($config->logPath . '/app.log', $config->appName);

        $this->memory = new Memory\MemoryStore(
            $config->memoryPath,
            $config->dataPath . '/auth',
            $this->logger,
        );

        $indexDir = dirname($config->indexPath);
        if (!is_dir($indexDir) && !mkdir($indexDir, 0775, true) && !is_dir($indexDir)) {
            throw new \RuntimeException("Cannot create index directory: {$indexDir}");
        }

        $this->search = new Memory\MemoryIndex(
            $this->memory,
            $config->indexPath,
            $this->logger,
            $config->indexEnabled,
        );

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

        $this->auth = new Auth\AuthorizationServer(
            $config,
            $this->tokens,
            $this->clients,
            $this->logger,
        );
    }
}
