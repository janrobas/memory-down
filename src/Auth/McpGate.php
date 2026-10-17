<?php

declare(strict_types=1);

namespace MemoryDown\Auth;

use MemoryDown\Config;
use MemoryDown\Support\BearerToken;
use MemoryDown\Users\User;
use MemoryDown\Users\UserStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Bearer authentication for the MCP endpoint (MemoryDown acting as an OAuth 2.1
 * resource server), which now also answers the question the endpoint could not
 * ask before: *whose* memory is this request for?
 *
 * This used to be a PSR-15 middleware in the transport's chain. It is a plain
 * gate instead, because the user has to be known before the MCP server is
 * built: the tools are constructed with a memory store, and with accounts there
 * is one store per person. So the order is authenticate → resolve the account →
 * build the server for that account, and the gate is what makes it possible.
 *
 * The challenge is unchanged from the middleware version: a 401 with
 * `WWW-Authenticate: Bearer resource_metadata="...", scope="memory:all"`,
 * which is what lets ChatGPT discover this authorization server (RFC 9728) and
 * start an OAuth flow. `error="invalid_token"` is added only when a token was
 * actually presented and rejected, so a client can tell "sign in" from "your
 * token is stale".
 *
 * A token is accepted only when all of the following hold:
 *   - the token exists and has not expired (TokenStore);
 *   - its audience is this server (base URL or /mcp, either is fine);
 *   - its `sub` names an account that still exists and is enabled;
 *   - its `epoch` is that account's current auth epoch, so a password change,
 *     a disable or a role change invalidates every token minted before it.
 */
final class McpGate
{
    public function __construct(
        private readonly TokenStore $tokens,
        private readonly UserStore $users,
        private readonly Config $config,
        private readonly LoggerInterface $logger = new \Psr\Log\NullLogger(),
    ) {
    }

    /**
     * The account this request acts as, or null when it may not proceed.
     */
    public function authenticate(ServerRequestInterface $request): ?User
    {
        $token = self::bearerToken($request);
        if ('' === $token) {
            $this->logger->info('mcp.auth.missing_token', ['path' => $request->getUri()->getPath()]);

            return null;
        }

        $record = $this->tokens->validateAccessToken($token);
        if (null === $record || !$this->audienceMatches((string) ($record['resource'] ?? ''))) {
            $this->logger->warning('mcp.auth.invalid_token');

            return null;
        }

        $user = $this->users->get((string) ($record['sub'] ?? ''));
        if (null === $user) {
            // A token with no subject, or one whose account is gone. Both are
            // unusable: there would be no corpus to serve.
            $this->logger->warning('mcp.auth.unknown_subject', ['client_id' => (string) ($record['client_id'] ?? '')]);

            return null;
        }
        if (!$user->enabled) {
            $this->logger->warning('mcp.auth.disabled_user', ['sub' => $user->id]);

            return null;
        }
        if ($user->authEpoch !== (int) ($record['epoch'] ?? 0)) {
            $this->logger->warning('mcp.auth.stale_epoch', ['sub' => $user->id]);

            return null;
        }

        return $user;
    }

    /**
     * The 401 that starts an OAuth flow, carrying the protected-resource
     * metadata URL for the path that was requested.
     */
    public function challenge(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $challenge = sprintf(
            'Bearer resource_metadata="%s", scope="%s"',
            $this->config->protectedResourceMetadataUrl($path),
            TokenStore::SCOPE,
        );
        if ('' !== self::bearerToken($request)) {
            $challenge .= ', error="invalid_token"';
        }

        $response = \Http\Discovery\Psr17FactoryDiscovery::findResponseFactory()->createResponse(401);
        $stream = \Http\Discovery\Psr17FactoryDiscovery::findStreamFactory()->createStream(
            '{"jsonrpc":"2.0","error":{"code":-32001,"message":"Unauthorized: OAuth 2.0 access token required"},"id":null}'
        );

        return $response
            ->withHeader('WWW-Authenticate', $challenge)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($stream);
    }

    public static function bearerToken(ServerRequestInterface $request): string
    {
        return BearerToken::fromRequest($request);
    }

    /**
     * The access token is audience-bound to the resource that was requested
     * during OAuth. Both canonical resources (base URL and /mcp) belong to
     * this server, so a token minted for either is accepted anywhere.
     */
    private function audienceMatches(string $resource): bool
    {
        $resource = rtrim($resource, '/');
        $base = rtrim($this->config->appBaseUrl, '/');

        return $resource === $base || $resource === $base . '/mcp';
    }
}
