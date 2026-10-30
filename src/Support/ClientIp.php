<?php

declare(strict_types=1);

namespace MemoryDown\Support;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The client IP of a request, for rate limiting.
 *
 * Uses the direct peer address only: forwarding headers (X-Forwarded-For,
 * X-Real-IP, Forwarded) are never trusted, because a client can forge them,
 * and a forged value would be both a way to pin somebody else's IP to a
 * bucket and a way to hop between buckets to dodge the limit entirely.
 */
final class ClientIp
{
    public static function of(ServerRequestInterface $request): string
    {
        $params = $request->getServerParams();

        return (string) ($params['REMOTE_ADDR'] ?? 'unknown');
    }
}
