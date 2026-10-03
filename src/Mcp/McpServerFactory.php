<?php

declare(strict_types=1);

namespace MemoryDown\Mcp;

use Mcp\Schema\ServerCapabilities;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use MemoryDown\Auth\BearerAuthMiddleware;
use MemoryDown\Kernel;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the MCP server (official mcp/sdk) for a single HTTP request.
 *
 * Serves both protocol eras from one endpoint:
 *  - handshake era (initialize + sessions, through 2025-11-25) — sessions are
 *    stored on disk so they survive across PHP-FPM/shared-hosting processes;
 *  - the stateless 2026-07-28 revision — no sessions at all.
 *
 * The bearer-token middleware wraps the transport, so every request (POST,
 * GET stream, DELETE session) is authenticated with the 401 + WWW-Authenticate
 * challenge described by RFC 9728 / the MCP authorization specification.
 */
final class McpServerFactory
{
    public const INSTRUCTIONS = <<<'TXT'
You have access to a persistent memory store backed by Markdown files.

Memory semantics:
- Store information that is durable and useful across conversations: user preferences, project context, decisions, recurring workflows, facts about people or systems, long-term context.
- Do NOT store every conversational detail; be selective, like a good personal memory.
- When you store something that clearly duplicates existing memory (same topic and same meaning), prefer updating the existing entry instead of creating a duplicate. Use recall/search_memory to check first.
- Never overwrite unrelated information.

Categories:
- preferences: durable user preferences and settings
- projects: ongoing project context and state
- decisions: decisions and the reasoning behind them
- facts: general durable facts
- people: information about people
- context: long-term situational context

Tags:
- Optional but recommended: when calling remember or update_memory, add 1-3 short lowercase tags (e.g. "project-x", "meeting-notes") to make memories easier to group and search later.

Tools:
- remember: create or update a memory
- recall: pull memories relevant to the conversation
- search_memory: full-text search over the corpus
- update_memory: update a specific entry by id
- forget_memory: delete an entry by id (destructive — only on explicit user request)
- list_memory: see what is stored
TXT;

    public static function build(ServerRequestInterface $request): Server
    {
        $kernel = Kernel::get();
        $config = $kernel->config;
        $logger = $kernel->logger;

        $tools = new MemoryTools($kernel->memory, $kernel->search);

        $builder = Server::builder()
            ->setServerInfo(
                name: $config->appName,
                version: $config->appVersion,
                description: 'Persistent Markdown-backed memory for AI agents.',
            )
            ->setLogger($logger)
            ->setInstructions(self::INSTRUCTIONS)
            ->setCapabilities(new ServerCapabilities(tools: true, resources: false, prompts: false))
            ->setSession(new FileSessionStore(
                $config->dataPath . '/sessions',
                $config->mcpSessionTtl,
            ));

        foreach ($tools->definitions() as $tool) {
            $builder->addTool(
                $tool['handler'],
                name: $tool['name'],
                description: $tool['description'],
                annotations: $tool['annotations'],
                inputSchema: $tool['inputSchema'],
            );
        }

        return $builder->build();
    }

    public static function transport(ServerRequestInterface $request): StreamableHttpTransport
    {
        $kernel = Kernel::get();
        $logger = $kernel->logger;

        // No DnsRebindingProtectionMiddleware: the endpoint is already gated by
        // OAuth bearer tokens and served behind a web server that validates
        // Host. Strict Origin allow-listing would reject ChatGPT's connector,
        // which may send an Origin header, with HTTP 403.
        $middleware = [
            new CorsMiddleware(),
            new BearerAuthMiddleware($kernel->tokens, $kernel->config, $logger),
        ];

        return new StreamableHttpTransport(
            request: $request,
            logger: $logger,
            middleware: $middleware,
        );
    }

    public static function run(ServerRequestInterface $request): ResponseInterface
    {
        $server = self::build($request);

        return $server->run(self::transport($request));
    }
}
