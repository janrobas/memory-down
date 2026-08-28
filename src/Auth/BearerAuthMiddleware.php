<?php

declare(strict_types=1);

namespace MemoryDown\Auth;

use MemoryDown\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * PSR-15 middleware enforcing bearer-token authentication on the MCP
 * endpoint (the MCP server acting as an OAuth 2.1 resource server).
 *
 * Unauthenticated and invalid-token requests receive a 401 with the RFC 9728
 * `WWW-Authenticate: Bearer resource_metadata="..."` challenge that lets
 * ChatGPT discover the authorization server and start an OAuth flow. The
 * metadata URL is derived from the request path so that its `resource` value
 * validates per RFC 9728 section 3.3.
 */
final class BearerAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly TokenStore $tokens,
        private readonly Config $config,
        private readonly LoggerInterface $logger = new \Psr\Log\NullLogger(),
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');
        $token = '';
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            $token = trim($m[1]);
        }

        if ('' !== $token) {
            $record = $this->tokens->validateAccessToken($token);
            if (null !== $record && $this->audienceMatches($record['resource'] ?? '')) {
                return $handler->handle($request->withAttribute('oauth.token', $record));
            }
            $this->logger->warning('mcp.auth.invalid_token');
        }

        $path = $request->getUri()->getPath();
        $metadataUrl = $this->config->protectedResourceMetadataUrl($path);

        $challenge = sprintf(
            'Bearer resource_metadata="%s", scope="%s"',
            $metadataUrl,
            TokenStore::SCOPE,
        );
        if ('' !== $token) {
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
