<?php

declare(strict_types=1);

namespace MemoryDown\Auth;

use MemoryDown\Config;
use MemoryDown\Support\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * The OAuth 2.1 authorization server MemoryDown itself runs (single-user).
 *
 * Implements the authorization-code flow with PKCE (S256), refresh-token
 * rotation, RFC 9207 issuer identification, dynamic client registration (DCR,
 * RFC 7591 — with a CIMD fallback for URL client_ids) and the discovery
 * documents MCP clients need:
 *
 *   GET /.well-known/oauth-authorization-server   (RFC 8414)
 *   GET /.well-known/openid-configuration         (OIDC discovery)
 *   GET/POST /oauth/authorize                     (consent)
 *   POST   /oauth/token                           (token endpoint)
 *   POST   /oauth/register                        (RFC 7591 DCR)
 *
 * Public clients only (token_endpoint_auth_method "none" + PKCE) in V1.
 */
final class AuthorizationServer
{
    private const ALLOWED_SCOPES = [TokenStore::SCOPE];

    public function __construct(
        private readonly Config $config,
        private readonly TokenStore $tokens,
        private readonly ClientRegistry $clients,
        private readonly LoggerInterface $logger = new \Psr\Log\NullLogger(),
        private readonly ?RateLimiter $limiter = null,
    ) {
    }

    /* ------------------------------------------------------------------ *
     *  Discovery documents
     * ------------------------------------------------------------------ */

    /**
     * RFC 8414 authorization server metadata (also served at the OIDC
     * discovery path; this document includes everything MCP clients need).
     *
     * @return array<string, mixed>
     */
    public function authorizationServerMetadata(): array
    {
        $base = $this->config->appBaseUrl;

        return [
            'issuer' => $base,
            'authorization_endpoint' => $base . '/oauth/authorize',
            'token_endpoint' => $base . '/oauth/token',
            'registration_endpoint' => $base . '/oauth/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => self::ALLOWED_SCOPES,
            'authorization_response_iss_parameter_supported' => true,
            'service_documentation' => $base . '/health',
        ];
    }

    /**
     * RFC 9728 protected resource metadata for the MCP endpoint at $path.
     *
     * @return array<string, mixed>
     */
    public function protectedResourceMetadata(string $path): array
    {
        return [
            'resource' => $this->config->resourceForPath($path),
            'authorization_servers' => [$this->config->appBaseUrl],
            'scopes_supported' => self::ALLOWED_SCOPES,
            'bearer_methods_supported' => ['header'],
            'resource_name' => $this->config->appName,
        ];
    }

    /* ------------------------------------------------------------------ *
     *  Authorization endpoint
     * ------------------------------------------------------------------ */

    /**
     * GET  → render consent page (validating the request first)
     * POST → approve or deny
     */
    public function handleAuthorize(ServerRequestInterface $request): ResponseInterface
    {
        if ('POST' === strtoupper($request->getMethod()) && !$this->withinRateLimit('consent', $request, $this->config->rateLimitConsentMax)) {
            return $this->tooMany();
        }

        $params = $this->params($request, mergeQuery: true);
        $errors = $this->validateAuthorizeRequest($params);
        $client = $this->paramsClient($params);
        $redirectUri = is_string($params['redirect_uri'] ?? null) ? $params['redirect_uri'] : '';
        $redirectSafe = in_array($redirectUri, $client['redirect_uris'], true);

        if ([] !== $errors) {
            $this->logger->warning('oauth.authorize.rejected', ['reason' => $errors]);

            // Never redirect to a redirect_uri that was not validated against
            // the client (open-redirector protection).
            if ($redirectSafe) {
                return $this->errorRedirect($redirectUri, $params, $errors[0]);
            }

            return $this->json(['error' => $errors[0], 'error_description' => 'Authorization request rejected'], 400);
        }

        if ('POST' === strtoupper($request->getMethod())) {
            $decision = strtolower((string) ($params['decision'] ?? ''));
            if ('deny' === $decision) {
                return $this->errorRedirect($redirectUri, $params, 'access_denied');
            }

            if ('allow' !== $decision) {
                return $this->consentPage($request, $client, $params, 'Invalid decision.');
            }

            $consentError = $this->validateConsent($params);
            if (null !== $consentError) {
                $this->logger->warning('oauth.consent.rejected');

                return $this->consentPage($request, $client, $params, $consentError);
            }

            $scope = $this->normalizeScope((string) ($params['scope'] ?? TokenStore::SCOPE));
            $resource = $this->normalizeResource((string) ($params['resource'] ?? $this->config->resourceForPath('/mcp')));
            $codeChallenge = (string) $params['code_challenge'];
            $code = $this->tokens->createCode(
                $client['client_id'],
                $redirectUri,
                $codeChallenge,
                $scope,
                $resource,
            );

            $this->logger->info('oauth.authorize.approved', ['client_id' => $client['client_id']]);

            $location = $this->appendParams($redirectUri, [
                'code' => $code,
                'iss' => $this->config->appBaseUrl,
            ]);
            if (isset($params['state'])) {
                $location = $this->appendParams($location, ['state' => (string) $params['state']]);
            }

            return $this->redirect($location);
        }

        return $this->consentPage($request, $client, $params, null);
    }

    /**
     * @return list<string>
     */
    private function validateAuthorizeRequest(array $params): array
    {
        if (($params['response_type'] ?? null) !== 'code') {
            return ['unsupported_response_type'];
        }
        if (!is_string($params['client_id'] ?? null) || '' === $params['client_id']) {
            return ['invalid_request'];
        }
        if (!is_string($params['redirect_uri'] ?? null) || '' === $params['redirect_uri']) {
            return ['invalid_request'];
        }
        $client = $this->clients->resolve($params['client_id']);
        if (null === $client) {
            return ['unauthorized_client'];
        }
        if (!in_array($params['redirect_uri'], $client['redirect_uris'], true)) {
            $this->logger->warning('oauth.authorize.redirect_mismatch', [
                'client_id' => $params['client_id'],
                'redirect_uri' => $params['redirect_uri'],
            ]);

            return ['invalid_request'];
        }
        if (!isset($params['code_challenge']) || !is_string($params['code_challenge']) || '' === $params['code_challenge']) {
            return ['invalid_request'];
        }
        $method = strtoupper((string) ($params['code_challenge_method'] ?? 'plain'));
        if ('S256' !== $method) {
            return ['invalid_request'];
        }
        if (strlen($params['code_challenge']) < 43 || strlen($params['code_challenge']) > 128) {
            return ['invalid_request'];
        }
        $scope = $this->normalizeScope((string) ($params['scope'] ?? TokenStore::SCOPE));
        if ([] === $scope) {
            return ['invalid_scope'];
        }
        if (isset($params['resource'])) {
            $resource = $this->normalizeResource((string) $params['resource']);
            if ('' === $resource) {
                return ['invalid_target'];
            }
        }

        return [];
    }

    /* ------------------------------------------------------------------ *
     *  Token endpoint
     * ------------------------------------------------------------------ */

    public function handleToken(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->withinRateLimit('token', $request, $this->config->rateLimitTokenMax)) {
            return $this->tooMany();
        }

        $params = $this->params($request);
        $grant = (string) ($params['grant_type'] ?? '');

        $result = match ($grant) {
            'authorization_code' => $this->grantAuthorizationCode($params),
            'refresh_token' => $this->grantRefreshToken($params),
            default => ['error' => 'unsupported_grant_type', 'error_description' => 'grant_type must be authorization_code or refresh_token'],
        };

        $status = isset($result['error']) ? 400 : 200;
        if (isset($result['error'])) {
            $this->logger->warning('oauth.token.rejected', ['grant' => $grant, 'error' => $result['error']]);
        } else {
            $this->logger->info('oauth.token.issued', ['grant' => $grant]);
        }

        return $this->json($result, $status, isset($result['error']) ? null : 60);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function grantAuthorizationCode(array $params): array
    {
        $code = (string) ($params['code'] ?? '');
        $codeVerifier = (string) ($params['code_verifier'] ?? '');
        $clientId = (string) ($params['client_id'] ?? '');

        $record = $this->tokens->consumeCode($code);
        if (null === $record) {
            return ['error' => 'invalid_grant', 'error_description' => 'Authorization code is invalid, expired or already used'];
        }
        if ($record['client_id'] !== $clientId) {
            return ['error' => 'invalid_grant', 'error_description' => 'client_id does not match the authorization code'];
        }

        $redirectUri = (string) ($params['redirect_uri'] ?? '');
        if ('' === $redirectUri || $redirectUri !== $record['redirect_uri']) {
            return ['error' => 'invalid_grant', 'error_description' => 'redirect_uri does not match the authorization code'];
        }

        $resource = $this->normalizeResource((string) ($params['resource'] ?? $record['resource']));
        if ('' === $resource) {
            $resource = $record['resource'];
        }

        return $this->issueTokens($clientId, $record['scope'], $resource, $codeVerifier, $record['code_challenge']);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function grantRefreshToken(array $params): array
    {
        $refresh = (string) ($params['refresh_token'] ?? '');
        $clientId = (string) ($params['client_id'] ?? '');

        $rotated = $this->tokens->rotateRefreshToken($refresh);
        if (null === $rotated) {
            return ['error' => 'invalid_grant', 'error_description' => 'Refresh token is invalid or expired'];
        }
        if ($rotated['client_id'] !== $clientId) {
            $this->tokens->revokeRefreshToken($rotated['refresh_token']);

            return ['error' => 'invalid_grant', 'error_description' => 'client_id does not match the refresh token'];
        }

        $resource = $this->normalizeResource((string) ($params['resource'] ?? $rotated['resource']));
        if ('' === $resource) {
            $resource = $rotated['resource'];
        }

        $access = $this->tokens->createAccessToken($clientId, $rotated['scope'], $resource);

        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => $this->config->oauthAccessTokenTtl,
            'scope' => implode(' ', $rotated['scope']),
            'refresh_token' => $rotated['refresh_token'],
            'resource' => $resource,
        ];
    }

    /**
     * @param string[] $scopes
     *
     * @return array<string, mixed>
     */
    private function issueTokens(string $clientId, array $scopes, string $resource, string $codeVerifier, string $codeChallenge): array
    {
        if ('' === $codeVerifier) {
            return ['error' => 'invalid_grant', 'error_description' => 'code_verifier is required for public clients'];
        }

        $derived = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        if (!hash_equals($codeChallenge, $derived)) {
            return ['error' => 'invalid_grant', 'error_description' => 'code_verifier does not match code_challenge'];
        }

        $access = $this->tokens->createAccessToken($clientId, $scopes, $resource);
        $refresh = $this->tokens->createRefreshToken($clientId, $scopes, $resource);

        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => $this->config->oauthAccessTokenTtl,
            'scope' => implode(' ', $scopes),
            'refresh_token' => $refresh,
            'resource' => $resource,
        ];
    }

    /* ------------------------------------------------------------------ *
     *  Dynamic client registration (RFC 7591)
     * ------------------------------------------------------------------ */

    public function handleRegister(ServerRequestInterface $request): ResponseInterface
    {
        if (!str_starts_with(strtolower($request->getHeaderLine('Content-Type')), 'application/json')) {
            return $this->json([
                'error' => 'invalid_client_metadata',
                'error_description' => 'Content-Type must be application/json.',
            ], 400);
        }

        $body = (string) $request->getBody();
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'invalid_client_metadata'], 400);
        }

        try {
            $client = $this->clients->register($payload);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'invalid_client_metadata', 'error_description' => $e->getMessage()], 400);
        }

        $this->logger->info('oauth.register.created', ['client_id' => $client['client_id']]);

        return $this->json($client, 201);
    }

    /* ------------------------------------------------------------------ *
     *  Helpers
     * ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $params
     *
     * @return array{client_id: string, client_name: string, redirect_uris: list<string>, token_endpoint_auth_method: string}
     */
    private function paramsClient(array $params): array
    {
        $clientId = (string) ($params['client_id'] ?? '');
        $client = $this->clients->resolve($clientId);

        return $client ?? [
            'client_id' => $clientId,
            'client_name' => $clientId,
            'redirect_uris' => [],
            'token_endpoint_auth_method' => 'none',
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function consentPage(
        ServerRequestInterface $request,
        array $client,
        array $params,
        ?string $error,
    ): ResponseInterface {
        $body = [];
        $body[] = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
        $body[] = '<meta name="viewport" content="width=device-width, initial-scale=1">';
        $body[] = '<title>Authorize access - ' . htmlspecialchars($this->config->appName, ENT_QUOTES) . '</title>';
        $body[] = '<style>body{font-family:system-ui,sans-serif;max-width:560px;margin:3rem auto;padding:0 1rem;color:#1a1a1a}'
            . '.card{border:1px solid #ddd;border-radius:10px;padding:1.5rem}'
            . 'h1{font-size:1.25rem}.muted{color:#666;word-break:break-all}'
            . 'button{margin-top:1rem;padding:.6rem 1.2rem;border-radius:6px;border:0;cursor:pointer;font-size:1rem}'
            . '.allow{background:#146c43;color:#fff;margin-right:.5rem}.deny{background:#eee}'
            . 'input[type=password],input[type=text]{width:100%;padding:.5rem;margin-top:1rem;border:1px solid #ccc;border-radius:6px}'
            . '.err{color:#b02a37;background:#f8d7da;border:1px solid #f5c2c7;padding:.6rem;border-radius:6px;margin-bottom:1rem}</style>';
        $body[] = '</head><body><div class="card">';
        $body[] = '<h1>' . htmlspecialchars($this->config->appName, ENT_QUOTES) . ' authorization</h1>';
        if (null !== $error) {
            $body[] = '<div class="err">' . htmlspecialchars($error, ENT_QUOTES) . '</div>';
        }
        $body[] = '<p><strong>' . htmlspecialchars((string) $client['client_name'], ENT_QUOTES) . '</strong> requests access to your memory.</p>';
        $body[] = '<p class="muted">Redirect URI: ' . htmlspecialchars((string) ($params['redirect_uri'] ?? ''), ENT_QUOTES) . '</p>';
        if (isset($params['scope'])) {
            $body[] = '<p class="muted">Scopes: ' . htmlspecialchars((string) $params['scope'], ENT_QUOTES) . '</p>';
        }
        if (isset($params['resource'])) {
            $body[] = '<p class="muted">Resource: ' . htmlspecialchars((string) $params['resource'], ENT_QUOTES) . '</p>';
        }
        $body[] = '<form method="post" action="' . htmlspecialchars($this->config->appBaseUrl . '/oauth/authorize', ENT_QUOTES) . '">';
        foreach (['client_id', 'redirect_uri', 'response_type', 'scope', 'state', 'code_challenge', 'code_challenge_method', 'resource'] as $keep) {
            if (isset($params[$keep])) {
                $value = is_array($params[$keep]) ? implode(' ', $params[$keep]) : (string) $params[$keep];
                $body[] = '<input type="hidden" name="' . htmlspecialchars($keep, ENT_QUOTES) . '" value="' . htmlspecialchars($value, ENT_QUOTES) . '">';
            }
        }
        if ('' !== $this->config->oauthUsername) {
            $body[] = '<input type="text" name="username" placeholder="Username" required>';
        }
        if ('' !== $this->config->oauthConsentPassword) {
            $body[] = '<input type="password" name="consent_password" placeholder="Consent password" required>';
        }
        $body[] = '<div>';
        $body[] = '<button type="submit" name="decision" value="allow" class="allow">Allow</button>';
        $body[] = '<button type="submit" name="decision" value="deny" class="deny">Deny</button>';
        $body[] = '</div></form></div></body></html>';

        return $this->html(implode("\n", $body));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function errorRedirect(string $redirectUri, array $params, string $error): ResponseInterface
    {
        $target = $redirectUri;
        if ('' === $target) {
            return $this->json(['error' => $error, 'error_description' => 'Authorization request rejected'], 400);
        }

        $extra = ['error' => $error];
        if (isset($params['state'])) {
            $extra['state'] = (string) $params['state'];
        }
        if ('access_denied' === $error || 'unsupported_response_type' === $error) {
            $extra['iss'] = $this->config->appBaseUrl;
        }

        return $this->redirect($this->appendParams($target, $extra));
    }

    /**
     * @return array<string, mixed>
     */
    private function params(ServerRequestInterface $request, bool $mergeQuery = false): array
    {
        $query = $request->getQueryParams();
        $query = is_array($query) ? $query : [];

        if ('POST' === strtoupper($request->getMethod())) {
            $type = strtolower($request->getHeaderLine('Content-Type'));
            if (str_starts_with($type, 'application/json')) {
                $decoded = json_decode((string) $request->getBody(), true);
                $body = is_array($decoded) ? $decoded : [];
            } else {
                $parsed = $request->getParsedBody();
                $body = is_array($parsed) ? $parsed : [];
            }

            return $mergeQuery ? array_merge($query, $body) : $body;
        }

        return $query;
    }

    /**
     * @param list<string>|string $scope
     *
     * @return list<string>
     */
    private function normalizeScope(array|string $scope): array
    {
        $scope = is_array($scope) ? implode(' ', $scope) : $scope;
        $requested = array_values(array_filter(preg_split('/\s+/', trim($scope)) ?: []));
        if ([] === $requested) {
            $requested = self::ALLOWED_SCOPES;
        }
        foreach ($requested as $value) {
            if (!in_array($value, self::ALLOWED_SCOPES, true)) {
                return [];
            }
        }

        return array_values(array_unique($requested));
    }

    private function normalizeResource(string $resource): string
    {
        $resource = rtrim($resource, '/');
        $base = $this->config->appBaseUrl;

        return in_array($resource, [$base, $base . '/mcp'], true) ? $resource : '';
    }

    /**
     * Validate the consent credentials (username and/or password) against the
     * configured values. Returns an error message, or null on success.
     *
     * @param array<string, mixed> $params
     */
    private function validateConsent(array $params): ?string
    {
        if ('' !== $this->config->oauthUsername) {
            $givenUser = (string) ($params['username'] ?? '');
            if (!hash_equals($this->config->oauthUsername, $givenUser)) {
                return 'Invalid username or password.';
            }
        }
        if ('' !== $this->config->oauthConsentPassword) {
            $givenPassword = (string) ($params['consent_password'] ?? '');
            if (!$this->consentPasswordValid($givenPassword)) {
                return 'Invalid username or password.';
            }
        }

        return null;
    }

    /**
     * The consent password may be a plaintext value or a bcrypt hash
     * (generated with password_hash). A bcrypt hash is preferred so the
     * secret never sits in the environment in plaintext.
     */
    private function consentPasswordValid(string $given): bool
    {
        $expected = $this->config->oauthConsentPassword;
        if ('' === $expected) {
            return true;
        }

        foreach (['$2y$', '$2a$', '$2b$'] as $prefix) {
            if (str_starts_with($expected, $prefix)) {
                return password_verify($given, $expected);
            }
        }

        return hash_equals($expected, $given);
    }

    /**
     * Apply a per-IP fixed-window limit for an endpoint. Returns true when the
     * request is allowed (including when limiting is disabled/unavailable).
     */
    private function withinRateLimit(string $bucket, ServerRequestInterface $request, int $max): bool
    {
        if (null === $this->limiter) {
            return true;
        }

        if ($this->limiter->allow(
            $bucket . ':' . $this->clientKey($request),
            $max,
            $this->config->rateLimitWindow,
        )) {
            return true;
        }

        $this->logger->warning('oauth.rate_limited', ['endpoint' => $bucket]);

        return false;
    }

    /**
     * Client key for rate limiting. Uses the direct peer address only; proxy
     * forwarding headers are not trusted because clients can forge them.
     */
    private function clientKey(ServerRequestInterface $request): string
    {
        $params = $request->getServerParams();

        return (string) ($params['REMOTE_ADDR'] ?? 'unknown');
    }

    private function tooMany(): ResponseInterface
    {
        return $this->json([
            'error' => 'temporarily_unavailable',
            'error_description' => 'Too many requests. Please try again shortly.',
        ], 429)->withHeader('Retry-After', (string) max(1, $this->config->rateLimitWindow));
    }

    private function appendParams(string $url, array $params): string
    {
        $fragment = strpos($url, '#');
        $base = false !== $fragment ? substr($url, 0, $fragment) : $url;
        $sep = str_contains($base, '?') ? '&' : '?';

        foreach ($params as $key => $value) {
            $base .= $sep . rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
            $sep = '&';
        }

        return $base;
    }

    private function redirect(string $location): ResponseInterface
    {
        $response = $this->response(302);
        $response->getBody()->write('');

        return $response->withHeader('Location', $location);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200, ?int $cacheSeconds = null): ResponseInterface
    {
        $response = $this->response($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
        if (null !== $cacheSeconds) {
            $response = $response->withHeader('Cache-Control', 'public, max-age=' . $cacheSeconds);
        }
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response;
    }

    private function html(string $html): ResponseInterface
    {
        $response = $this->response(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
        $response->getBody()->write($html);

        return $response;
    }

    private function response(int $status): ResponseInterface
    {
        /** @var StreamFactoryInterface $factory */
        $factory = \Http\Discovery\Psr17FactoryDiscovery::findStreamFactory();
        $response = \Http\Discovery\Psr17FactoryDiscovery::findResponseFactory()->createResponse($status);

        return $response->withBody($factory->createStream(''));
    }
}
