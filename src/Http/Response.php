<?php

declare(strict_types=1);

namespace MemoryDown\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Response helpers: security headers and an SSE-aware SAPI emitter.
 *
 * The MCP transport can return a non-seekable SSE callback stream; the simple
 * `echo $body` used by most emitters buffers that stream instead of flushing
 * it. This emitter reads non-seekable bodies in chunks and flushes, which is
 * what the Streamable HTTP transport needs.
 */
final class Response
{
    /**
     * Apply security headers to a response.
     */
    public static function secure(ResponseInterface $response): ResponseInterface
    {
        $isHtml = str_contains($response->getHeaderLine('Content-Type'), 'text/html');
        $isAdmin = $response->hasHeader(\MemoryDown\Web\WebApp::ADMIN_HEADER);

        if ($isAdmin) {
            $response = $response->withoutHeader(\MemoryDown\Web\WebApp::ADMIN_HEADER);
        }

        $policy = match (true) {
            // Admin UI: same-origin assets only; no inline scripts, no CDN.
            $isAdmin => "default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
                . "img-src 'self' data:; font-src 'self'; connect-src 'self'; "
                . "base-uri 'none'; form-action 'self'; frame-ancestors 'none'",
            $isHtml => "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'",
            default => "default-src 'none'",
        };

        return $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Content-Security-Policy', $policy);
    }

    /**
     * Emit a PSR-7 response to the SAPI, handling both seekable (JSON/HTML)
     * and non-seekable (SSE callback) bodies.
     */
    public static function emit(ResponseInterface $response): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code($response->getStatusCode());

        $hasContentLength = false;
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                if ('content-length' === strtolower($name)) {
                    $hasContentLength = true;
                }
                header(sprintf('%s: %s', $name, $value), false);
            }
        }

        $body = $response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
            $contents = $body->getContents();
            if (!$hasContentLength && '' !== $contents) {
                header('Content-Length: ' . strlen($contents));
            }
            echo $contents;

            return;
        }

        // Non-seekable stream (SSE): read in chunks and flush so the client
        // receives events as they are produced, not after buffering.
        while (!$body->eof()) {
            echo $body->read(8192);
            flush();
        }
    }
}
