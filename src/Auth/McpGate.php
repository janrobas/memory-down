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
 * Bearer authentication for the MCP endpoints (MemoryDown acting as an OAuth 2.1
 * resource server), which also answers the question an endpoint cannot ask
 * before it authenticates: *whose* memory is this request for?
 *
 * This used to be a PSR-15 middleware in the transport's chain. It is a plain
 * gate instead, because the account has to be known before the MCP server is
 * built: the tools are constructed with a corpus, and with accounts there is one
 * corpus per person. So the order is authenticate → resolve the account → build
 * the server for that account, and the gate is what makes it possible.
 *
 * The shape of a refusal is the protocol's, and deliberate:
 *
 *  - **401 with `WWW-Authenticate: Bearer resource_metadata="…", scope="…"`**
 *    when there is no usable token. That is what lets ChatGPT discover this
 *    authorization server (RFC 9728) and start an OAuth flow. `error=
 *    "invalid_token"` is added only when a token was presented and rejected, so
 *    a client can tell "sign in" from "your token is stale".
 *  - **403 with no challenge** when the token is perfectly valid but its account
 *    is not an admin, and the request is for the admin endpoint (`/mcp/all`).
 *    A 401 here would send the client back into OAuth forever, because it would
 *    read "authenticate again" instead of "this door is closed to you".
 *
 * A token is accepted only when all of the following hold:
 *   - the token exists and has not expired (TokenStore);
 *   - its audience is the endpoint that was requested — the base URL and
 *     `/mcp` share one audience, `/mcp/all` has its own, so admin reach is
 *     never inherited from an ordinary connector;
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
     * The account this request acts as, or the refusal to send back.
     *
     * The caller's next step differs by return type, on purpose: a User means
     * build the server, a ResponseInterface means return it unchanged.
     *
     * @param bool $adminEndpoint the request is for /mcp/all, which only
     *                            administrators may use
     */
    public function actor(ServerRequestInterface $request, bool $adminEndpoint = false): User|ResponseInterface
    {
        $token = self::bearerToken($request);
        if ('' === $token) {
            $this->logger->info('mcp.auth.missing_token', ['path' => $request->getUri()->getPath()]);

            return $this->challenge($request);
        }

        $record = $this->tokens->validateAccessToken($token);
        if (null === $record || !$this->audienceMatches((string) ($record['resource'] ?? ''), $adminEndpoint)) {
            $this->logger->warning('mcp.auth.invalid_token', ['admin_endpoint' => $adminEndpoint]);

            return $this->challenge($request);
        }

        $user = $this->users->get((string) ($record['sub'] ?? ''));
        if (null === $user) {
            // A token with no subject, or one whose account is gone. Both are
            // unusable: there would be no corpus to serve.
            $this->logger->warning('mcp.auth.unknown_subject', ['client_id' => (string) ($record['client_id'] ?? '')]);

            return $this->challenge($request);
        }
        if (!$user->enabled) {
            $this->logger->warning('mcp.auth.disabled_user', ['sub' => $user->id]);

            return $this->challenge($request);
        }
        if ($user->authEpoch !== (int) ($record['epoch'] ?? 0)) {
            $this->logger->warning('mcp.auth.stale_epoch', ['sub' => $user->id]);

            return $this->challenge($request);
        }
        if ($adminEndpoint && !$user->isAdmin()) {
            // Authenticated, enabled, current — and still not allowed. Only a
            // role change fixes this, and a role change moves the epoch, so the
            // next request cannot arrive with this token either.
            $this->logger->warning('mcp.auth.not_admin', ['sub' => $user->id]);

            return $this->forbidden();
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

        return $this->jsonRpcError(401, -32001, 'Unauthorized: OAuth 2.0 access token required')
            ->withHeader('WWW-Authenticate', $challenge);
    }

    /**
     * The 403 for "signed in, but not an administrator".
     *
     * No `WWW-Authenticate` header: the credentials are fine and re-running the
     * flow would change nothing, so the client must not be invited to try.
     */
    public function forbidden(): ResponseInterface
    {
        return $this->jsonRpcError(
            403,
            -32003,
            'Forbidden: this endpoint serves every account and is restricted to administrators',
        );
    }

    public static function bearerToken(ServerRequestInterface $request): string
    {
        return BearerToken::fromRequest($request);
    }

    private function jsonRpcError(int $status, int $code, string $message): ResponseInterface
    {
        $payload = json_encode(
            ['jsonrpc' => '2.0', 'error' => ['code' => $code, 'message' => $message], 'id' => null],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        $response = \Http\Discovery\Psr17FactoryDiscovery::findResponseFactory()->createResponse($status);
        $stream = \Http\Discovery\Psr17FactoryDiscovery::findStreamFactory()->createStream((string) $payload);

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($stream);
    }

    /**
     * The access token is audience-bound to the resource that was requested
     * during OAuth.
     *
     * The base URL and `/mcp` are one audience (a client may probe either), and
     * `/mcp/all` is another. They are deliberately not interchangeable: a token
     * consented for one memory store must not silently become a token for every
     * memory store on the instance.
     */
    private function audienceMatches(string $resource, bool $adminEndpoint): bool
    {
        $resource = rtrim($resource, '/');
        $base = rtrim($this->config->appBaseUrl, '/');

        if ($adminEndpoint) {
            return $resource === $base . Config::MCP_ALL_PATH;
        }

        return $resource === $base || $resource === $base . '/mcp';
    }
}
