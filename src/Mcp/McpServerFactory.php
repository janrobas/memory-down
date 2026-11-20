<?php

declare(strict_types=1);

namespace MemoryDown\Mcp;

use Mcp\Schema\ServerCapabilities;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use MemoryDown\Kernel;
use MemoryDown\Users\User;
use MemoryDown\Users\UserCorpora;
use MemoryDown\Users\UserServices;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Builds the MCP server (official mcp/sdk) for a single HTTP request.
 *
 * Serves both protocol eras from one endpoint:
 *  - handshake era (initialize + sessions, through 2025-11-25) — sessions are
 *    stored on disk so they survive across PHP-FPM/shared-hosting processes;
 *  - the stateless 2026-07-28 revision — no sessions at all.
 *
 * Authentication happens HERE, before the server is built, rather than in the
 * transport's middleware chain: with accounts, the tools are constructed around
 * one user's corpus, so the user has to be known first. The gate produces the
 * same RFC 9728 401 challenge the middleware used to, and everything after it
 * runs with the corpus of the account the bearer token names.
 *
 * Two endpoints, one tool set:
 *
 *  - `/mcp` (and the base URL) is the ordinary connector: every account is one
 *    connector, and the same URL behaves as whoever signed in on the consent
 *    page. The tools can reach exactly that account.
 *  - `/mcp/all` is the admin connector: administrators only (the gate answers
 *    403, not 401, for anybody else), and it serves every enabled account at
 *    once. It has its own audience, its own sessions and its own instructions,
 *    so the two never contaminate each other.
 *
 * MCP sessions are kept per user (data/sessions/mcp/<id>), and the admin
 * connector's sessions live one level deeper (<id>/all), so a session id
 * belonging to one account — or one connector — cannot be resumed by another.
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
- search_memory and list_memory return short snippets and metadata only: call read_memory to read an entry in full, and always read an entry before rewriting it with update_memory.

Categories:
- preferences: durable user preferences and settings
- projects: ongoing project context and state
- decisions: decisions and the reasoning behind them
- workflows: recurring processes, procedures and habits the user follows
- ideas: thoughts, proposals or possibilities worth keeping
- references: durable pointers — links, systems, docs, configs
- goals: things the user wants to achieve
- facts: general durable facts
- people: information about people
- context: long-term situational context
- notes: catch-all for anything that does not clearly fit the categories above
- writings: the user's own posts, published read-only through the public writings API. READ-ONLY through MCP: never create, edit, move or delete anything in this category, and never move another memory into it. To draft a post, store it in `ideas` or `notes` and tell the user to move it into writings in the admin UI.

Tags:
- Optional but recommended: when calling remember or update_memory, add 1-3 short lowercase tags (e.g. "project-x", "meeting-notes") to make memories easier to group and search later.

Tools:
- remember: create or update a memory
- recall: pull memories relevant to the conversation
- search_memory: full-text search over the corpus (returns short snippets)
- read_memory: read one entry in full by id or path (search/list return snippets only)
- update_memory: update a specific entry by id (read_memory it first)
- forget_memory: delete an entry by id (destructive — only on explicit user request)
- list_memory: see what is stored
TXT;

    /**
     * The admin connector's extra instructions. The base text still applies;
     * this says what is different about a connection that sees every account.
     */
    public const INSTRUCTIONS_ADMIN = <<<'TXT'
ACCOUNT SCOPE — THIS CONNECTION SERVES EVERY ENABLED ACCOUNT ON THIS INSTANCE.

You are not limited to one person's memory here. Every tool takes an optional "owner" (an account name such as "alice"):
- Every result carries "owner": the account it came from. When you report something found in another account, name that account, so it is never mistaken for the user's own memory.
- Reads (search_memory, recall, read_memory, list_memory) search EVERY account when "owner" is omitted, and one account when it is given. Prefer "owner" once you know whose memory you need — an unfiltered recall mixes several people's context.
- Writes (remember, update_memory, forget_memory) go to "owner", defaulting to your own account. Writing into somebody else's memory is a real change to their data: do it only when the user asked for it, and say which account you are writing to.
- An entry cannot be moved between accounts. "owner" locates an entry; it never relocates one.
- Deleting moves the entry into THAT account's trash, where only its owner can restore it from the admin UI. Treat a cross-account delete as permanent.
- Disabled accounts are not visible at all: they cannot be searched, read or written.
TXT;

    public static function build(UserServices $services, string $userId): Server
    {
        $kernel = Kernel::get();

        return self::server(
            new MemoryTools(UserCorpora::only($kernel->users, $kernel->userServices, $userId), $userId),
            $userId,
            self::INSTRUCTIONS . "\n\n" . \MemoryDown\Memory\RecommendedTags::summary(),
        );
    }

    /**
     * The server behind /mcp/all: every enabled account, one set of tools.
     *
     * The acting account is still the token's subject — it decides the default
     * write target and whose sessions these are — but the corpus is everything
     * the instance holds.
     */
    public static function buildAdmin(User $admin): Server
    {
        $kernel = Kernel::get();
        $corpora = new UserCorpora($kernel->users, $kernel->userServices);

        return self::server(
            new MemoryTools($corpora, $admin->id, crossAccount: true),
            $admin->id . DIRECTORY_SEPARATOR . 'all',
            self::INSTRUCTIONS . "\n\n" . self::INSTRUCTIONS_ADMIN . "\n\n" . \MemoryDown\Memory\RecommendedTags::summary(),
        );
    }

    private static function server(MemoryTools $tools, string $sessionScope, string $instructions): Server
    {
        $kernel = Kernel::get();
        $config = $kernel->config;

        $builder = Server::builder()
            ->setServerInfo(
                name: $config->appName,
                version: $config->appVersion,
                description: 'Persistent Markdown-backed memory for AI agents.',
            )
            ->setLogger($kernel->logger)
            ->setInstructions($instructions)
            ->setCapabilities(new ServerCapabilities(tools: true, resources: false, prompts: false))
            ->setSession(new FileSessionStore(
                $config->mcpSessionsPath . DIRECTORY_SEPARATOR . $sessionScope,
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

        // CORS only. Authentication is not middleware any more: the account has
        // to be known before the tools above are built, so it happens in run().
        // The 401 is the gate's, unchanged in shape.
        //
        // No DnsRebindingProtectionMiddleware either: the endpoint is already
        // gated by OAuth bearer tokens and served behind a web server that
        // validates Host. Strict Origin allow-listing would reject ChatGPT's
        // connector, which may send an Origin header, with HTTP 403.
        return new StreamableHttpTransport(
            request: $request,
            logger: $kernel->logger,
            middleware: [new CorsMiddleware()],
        );
    }

    public static function run(ServerRequestInterface $request): ResponseInterface
    {
        $kernel = Kernel::get();

        $actor = $kernel->mcpGate->actor($request);
        if ($actor instanceof ResponseInterface) {
            return $actor;
        }

        $server = self::build($kernel->userServices->for($actor->id), $actor->id);

        return $server->run(self::transport($request));
    }

    /**
     * /mcp/all: everything, for administrators only.
     */
    public static function runAdmin(ServerRequestInterface $request): ResponseInterface
    {
        $kernel = Kernel::get();

        $actor = $kernel->mcpGate->actor($request, adminEndpoint: true);
        if ($actor instanceof ResponseInterface) {
            return $actor;
        }

        return self::buildAdmin($actor)->run(self::transport($request));
    }
}
