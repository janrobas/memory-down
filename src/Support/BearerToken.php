<?php

declare(strict_types=1);

namespace MemoryDown\Support;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Reading a bearer token off a request.
 *
 * One implementation for the two places that authenticate a bearer: the MCP
 * endpoint (whose token is an OAuth access token) and the read-only publishing
 * APIs (whose token is an account's API token). Both have to accept exactly the
 * same header syntax, and a second, slightly different regex is how a
 * "Authorization: bearer  x" that works on one endpoint ends up rejected on the
 * other.
 *
 * Returns '' when no bearer token was presented. It never validates anything —
 * the caller decides what a token is worth.
 */
final class BearerToken
{
    private const PATTERN = '/^Bearer\s+(.+)$/i';

    public static function fromRequest(ServerRequestInterface $request): string
    {
        if (1 !== preg_match(self::PATTERN, $request->getHeaderLine('Authorization'), $m)) {
            return '';
        }

        return trim($m[1]);
    }
}
