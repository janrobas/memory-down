<?php

declare(strict_types=1);

namespace MemoryDown\Http;

use MemoryDown\Config;
use MemoryDown\Kernel;
use MemoryDown\Mcp\McpServerFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Front controller: routes one HTTP request to the right handler.
 *
 * Routes:
 *   GET  /health, /health/mcp, /health/oauth        diagnostics (no secrets)
 *   GET  /.well-known/oauth-protected-resource[/mcp]  RFC 9728 metadata
 *   GET  /.well-known/oauth-authorization-server      RFC 8414 metadata
 *   GET  /.well-known/openid-configuration            OIDC discovery
 *   GET  /oauth/authorize                             consent page
 *   POST /oauth/authorize                             consent decision
 *   POST /oauth/token                                 OAuth token endpoint
 *   POST /oauth/register                              RFC 7591 DCR
 *   POST /mcp, GET /mcp, DELETE /mcp                  MCP (Streamable HTTP)
 *   POST /, GET /                                     MCP at the base URL too
 *   GET  /                                             landing page for humans
 *
 * ChatGPT may probe the base URL instead of /mcp, so the MCP endpoint is
 * mounted at both paths.
 */
final class App
{
    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function fromGlobals(): self
    {
        $kernel = Kernel::get();

        return new self($kernel->config, $kernel->logger);
    }

    public function run(): void
    {
        $request = $this->createRequest();

        $response = $this->dispatch($request)
            ->withHeader('X-Request-Id', $this->logger->requestId());

        Response::emit(Response::secure($response));
    }

    private function createRequest(): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $creator = new ServerRequestCreator($factory, $factory, $factory, $factory);

        return $creator->fromGlobals();
    }

    private function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        $path = '/' . trim($request->getUri()->getPath(), '/');
        $method = strtoupper($request->getMethod());

        try {
            return match (true) {
                str_starts_with($path, '/assets/') && 'GET' === $method => $this->asset($path),
                str_starts_with($path, '/ui') => \MemoryDown\Web\WebApp::fromKernel()->handle($request),
                '/health' === $path && 'GET' === $method => $this->json($this->health()),
                '/health/mcp' === $path && 'GET' === $method => $this->json($this->healthMcp()),
                '/health/oauth' === $path && 'GET' === $method => $this->json($this->healthOauth()),
                '/.well-known/oauth-protected-resource' === $path && 'GET' === $method => $this->json(Kernel::get()->auth->protectedResourceMetadata('/'), 200, 300),
                '/.well-known/oauth-protected-resource/mcp' === $path && 'GET' === $method => $this->json(Kernel::get()->auth->protectedResourceMetadata('/mcp'), 200, 300),
                in_array($path, ['/.well-known/oauth-authorization-server', '/.well-known/openid-configuration'], true) && 'GET' === $method => $this->json(Kernel::get()->auth->authorizationServerMetadata(), 200, 300),
                '/oauth/authorize' === $path => Kernel::get()->auth->handleAuthorize($request),
                '/oauth/token' === $path && 'POST' === $method => Kernel::get()->auth->handleToken($request),
                '/oauth/register' === $path && 'POST' === $method => Kernel::get()->auth->handleRegister($request),
                '/mcp' === $path && in_array($method, ['POST', 'GET', 'DELETE'], true) => McpServerFactory::run($request),
                '/' === $path && 'POST' === $method => McpServerFactory::run($request),
                '/' === $path && 'GET' === $method && $this->wantsEventStream($request) => McpServerFactory::run($request),
                '/' === $path && 'GET' === $method => $this->landingPage(),
                default => $this->notFound(),
            };
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            $secret = $this->config->oauthConsentPassword;
            if ('' !== $secret && strlen($secret) >= 4) {
                $message = str_replace($secret, '[REDACTED]', $message);
            }
            $this->logger->error('http.unhandled_exception', ['message' => $message, 'class' => $e::class, 'path' => $path]);

            return $this->json(['error' => 'internal_error'], 500);
        }
    }

    private function wantsEventStream(ServerRequestInterface $request): bool
    {
        return str_contains(strtolower($request->getHeaderLine('Accept')), 'text/event-stream');
    }

    /**
     * Serve a static admin asset (CSS/JS) from public_html/assets.
     *
     * Apache serves these directly from .htaccess; this fallback covers the
     * PHP built-in server and hosts where that rewrite is unavailable. Only a
     * strict filename whitelist inside a single directory is ever served.
     */
    private function asset(string $path): ResponseInterface
    {
        $name = substr($path, strlen('/assets/'));

        $types = [
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'ico' => 'image/x-icon',
        ];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (!isset($types[$ext])
            || 1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.(css|js|svg|png|ico)$/', $name)
            || str_contains($name, '..')
        ) {
            return $this->notFound();
        }

        $file = dirname(__DIR__, 2) . '/public_html/assets/' . $name;
        if (!is_file($file)) {
            return $this->notFound();
        }

        $contents = file_get_contents($file);
        if (false === $contents) {
            return $this->notFound();
        }

        $mtime = (int) @filemtime($file);
        $etag = '"' . dechex($mtime) . '-' . dechex((int) @filesize($file)) . '"';

        // Revalidate on every load so UI updates take effect immediately; a
        // matching validator lets the client reuse its cached copy.
        if ($request = $_SERVER['HTTP_IF_NONE_MATCH'] ?? null) {
            if (trim((string) $request) === $etag) {
                return (new Psr17Factory())->createResponse(304)
                    ->withHeader('ETag', $etag)
                    ->withHeader('Cache-Control', 'no-cache');
            }
        }

        $response = (new Psr17Factory())->createResponse(200)
            ->withHeader('Content-Type', $types[$ext])
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('ETag', $etag)
            ->withHeader('Last-Modified', gmdate('D, d M Y H:i:s', $mtime) . ' GMT')
            ->withHeader('X-Content-Type-Options', 'nosniff');
        $response->getBody()->write($contents);

        return $response;
    }

    /* ------------------------------------------------------------------ *
     *  Diagnostics
     * ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function health(): array
    {
        $dataPath = $this->config->dataPath;
        $memoryPath = $this->config->memoryPath;

        return [
            'status' => 'ok',
            'app' => $this->config->appName,
            'version' => $this->config->appVersion,
            'env' => $this->config->appEnv,
            'time' => gmdate('c'),
            'checks' => [
                'php' => PHP_VERSION,
                'php_ok' => version_compare(PHP_VERSION, '8.2.0', '>='),
                'memory_path' => $memoryPath,
                'memory_writable' => is_writable($memoryPath),
                'memory_entries' => Kernel::get()->memory->count(),
                'data_writable' => is_writable($dataPath),
                'sessions_writable' => is_writable($dataPath . '/sessions'),
                'log_writable' => is_writable($this->config->logPath),
                'search_index' => Kernel::get()->search instanceof \MemoryDown\Memory\MemoryIndex
                    ? Kernel::get()->search->status()
                    : ['engine' => 'direct', 'available' => true],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function healthMcp(): array
    {
        $versions = static fn (array $cases): array => array_map(static fn ($v): string => $v->value, $cases);

        return [
            'status' => 'ok',
            'endpoints' => ['/mcp', '/'],
            'transport' => 'streamable-http (POST/GET/DELETE)',
            'protocol_eras' => [
                'handshake' => $versions(\Mcp\Schema\Enum\ProtocolVersion::handshakeVersions()),
                'modern' => $versions(\Mcp\Schema\Enum\ProtocolVersion::modernVersions()),
            ],
            'tools' => array_map(
                static fn (array $t): string => $t['name'],
                (new \MemoryDown\Mcp\MemoryTools(Kernel::get()->memory, Kernel::get()->search))->definitions(),
            ),
            'session_store' => 'file',
            'sessions_writable' => is_writable($this->config->dataPath . '/sessions'),
        ];
    }

    /** @return array<string, mixed> */
    private function healthOauth(): array
    {
        $base = $this->config->appBaseUrl;

        return [
            'status' => 'ok',
            'metadata' => [
                'protected_resource' => [
                    $base . '/.well-known/oauth-protected-resource',
                    $base . '/.well-known/oauth-protected-resource/mcp',
                ],
                'authorization_server' => [
                    $base . '/.well-known/oauth-authorization-server',
                    $base . '/.well-known/openid-configuration',
                ],
            ],
            'endpoints' => [
                'authorize' => $base . '/oauth/authorize',
                'token' => $base . '/oauth/token',
                'register' => $base . '/oauth/register',
            ],
            'supported' => [
                'pkce_methods' => ['S256'],
                'token_endpoint_auth_methods' => ['none'],
                'grant_types' => ['authorization_code', 'refresh_token'],
                'issuer_identification_rfc9207' => true,
                'dynamic_client_registration' => true,
                'client_id_metadata_documents' => [
                    'advertised' => false,
                    'accepted_as_fallback' => true,
                    'allowed_origins' => $this->config->oauthCimdAllowedOrigins,
                ],
            ],
            'token_lifetimes_seconds' => [
                'access' => $this->config->oauthAccessTokenTtl,
                'refresh' => $this->config->oauthRefreshTokenTtl,
                'code' => $this->config->oauthCodeTtl,
            ],
            'consent_password_enabled' => '' !== $this->config->oauthConsentPassword,
        ];
    }

    /* ------------------------------------------------------------------ *
     *  Responses
     * ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200, ?int $cacheSeconds = null): ResponseInterface
    {
        $response = (new Psr17Factory())->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', null !== $cacheSeconds ? 'public, max-age=' . $cacheSeconds : 'no-store');
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $response;
    }

    private function landingPage(): ResponseInterface
    {
        $uiLink = $this->config->uiEnabled
            ? '<li><a href="/ui">Admin UI — browse &amp; edit memories</a></li>'
            : '';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<title>{$this->config->appName}</title>
<style>
body{font-family:system-ui,sans-serif;max-width:640px;margin:3rem auto;padding:0 1rem;color:#1a1a1a}
code{background:#f3f4f6;padding:.1rem .35rem;border-radius:4px}
a{color:#146c43}
</style>
</head>
<body>
<h1>{$this->config->appName}</h1>
<p>Personal AI memory server (v{$this->config->appVersion}). Persistent memory is stored as Markdown files.</p>
<p>This host serves the MCP endpoint at <code>/mcp</code> (and at the base URL) over Streamable HTTP with OAuth 2.1.</p>
<ul>
{$uiLink}
<li><a href="/health">/health</a></li>
<li><a href="/health/mcp">/health/mcp</a></li>
<li><a href="/health/oauth">/health/oauth</a></li>
<li><a href="/.well-known/oauth-protected-resource">/.well-known/oauth-protected-resource</a></li>
<li><a href="/.well-known/oauth-authorization-server">/.well-known/oauth-authorization-server</a></li>
</ul>
</body>
</html>
HTML;

        $response = (new Psr17Factory())->createResponse(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
        $response->getBody()->write($html);

        return $response;
    }

    private function notFound(): ResponseInterface
    {
        $response = (new Psr17Factory())->createResponse(404)
            ->withHeader('Content-Type', 'application/json');
        $response->getBody()->write('{"error":"not_found"}');

        return $response;
    }
}
