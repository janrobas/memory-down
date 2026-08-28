<?php

declare(strict_types=1);

namespace MemoryDown\Auth;

use MemoryDown\Support\JsonStore;
use Psr\Log\LoggerInterface;

/**
 * Fetches and validates OAuth Client ID Metadata Documents (CIMD,
 * draft-ietf-oauth-client-id-metadata-document) when an MCP client presents
 * an HTTPS URL as its client_id (this is what ChatGPT does today).
 *
 * Security: only allowlisted origins are fetched, HTTPS only, resolved IPs
 * are checked against private/reserved ranges (SSRF defence), documents are
 * validated (client_id must equal the URL, required fields present, redirect
 * URIs https or loopback http) and cached with a short TTL.
 */
final class CimdFetcher
{
    private const CACHE_TTL = 3600;
    private const FETCH_TIMEOUT = 6;

    /** @var list<string> */
    private array $allowedOrigins;

    private JsonStore $cache;

    public function __construct(
        string $authDataDir,
        array $allowedOrigins,
        private readonly LoggerInterface $logger = new \Psr\Log\NullLogger(),
    ) {
        $this->allowedOrigins = array_values(array_map('strtolower', $allowedOrigins));
        $dir = $authDataDir . '/cimd';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create CIMD cache directory: {$dir}");
        }
        $this->cache = new JsonStore($dir . '/cache.json');
    }

    /**
     * Resolve a URL-formatted client_id to a client definition, or null.
     *
     * @return array{client_id: string, client_name: string, redirect_uris: list<string>, token_endpoint_auth_method: string}|null
     */
    public function fetch(string $clientId): ?array
    {
        if (!str_starts_with($clientId, 'https://')) {
            return null;
        }

        $key = 'cimd_' . hash('sha256', $clientId);
        $cached = $this->cache->get($key);
        if (null !== $cached && ($cached['expires_at'] ?? 0) > time()) {
            return $cached['client'] ?? null;
        }

        $parsed = parse_url($clientId);
        if (!is_array($parsed) || !isset($parsed['host']) || !isset($parsed['scheme'])) {
            $this->logger->warning('cimd.invalid_url', ['client_id' => substr($clientId, 0, 80)]);
            $this->cache->put($key, ['expires_at' => time() + 300, 'client' => null]);

            return null;
        }

        $host = strtolower((string) $parsed['host']);
        if (!$this->isOriginAllowed($host)) {
            $this->logger->warning('cimd.origin_denied', ['host' => $host]);
            $this->cache->put($key, ['expires_at' => time() + 300, 'client' => null]);

            return null;
        }

        if (!$this->hostResolvesPublicly($host)) {
            $this->logger->warning('cimd.ssrf_blocked', ['host' => $host]);
            $this->cache->put($key, ['expires_at' => time() + 300, 'client' => null]);

            return null;
        }

        $body = $this->httpGet($clientId);
        if (null === $body) {
            $this->logger->warning('cimd.fetch_failed', ['host' => $host]);
            $this->cache->put($key, ['expires_at' => time() + 120, 'client' => null]);

            return null;
        }

        $doc = json_decode($body, true);
        if (!is_array($doc)) {
            $this->logger->warning('cimd.not_json', ['host' => $host]);
            $this->cache->put($key, ['expires_at' => time() + 300, 'client' => null]);

            return null;
        }

        $client = $this->validate($doc, $clientId);
        if (null === $client) {
            $this->logger->warning('cimd.validation_failed', ['host' => $host]);
            $this->cache->put($key, ['expires_at' => time() + 300, 'client' => null]);

            return null;
        }

        $this->cache->put($key, ['expires_at' => time() + self::CACHE_TTL, 'client' => $client]);

        return $client;
    }

    private function isOriginAllowed(string $host): bool
    {
        if ('127.0.0.1' === $host || 'localhost' === $host || '[::1]' === $host || '::1' === $host) {
            return in_array('localhost', $this->allowedOrigins, true)
                || in_array('127.0.0.1', $this->allowedOrigins, true);
        }

        foreach ($this->allowedOrigins as $origin) {
            if ($host === $origin || str_ends_with($host, '.' . $origin)) {
                return true;
            }
        }

        return false;
    }

    /**
     * SSRF guard: reject hosts that resolve only to private/reserved IPs.
     */
    private function hostResolvesPublicly(string $host): bool
    {
        if ('localhost' === $host || '127.0.0.1' === $host || '::1' === $host || '[::1]' === $host) {
            return true;
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (false === $records || [] === $records) {
            return false;
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (null === $ip || $this->isPrivateIp((string) $ip)) {
                return false;
            }
        }

        return true;
    }

    private function isPrivateIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $blocked = [
                '10.0.0.0/8',
                '127.0.0.0/8',
                '169.254.0.0/16',
                '172.16.0.0/12',
                '192.168.0.0/16',
                '100.64.0.0/10',
                '0.0.0.0/8',
                '224.0.0.0/4',
            ];
            foreach ($blocked as $cidr) {
                [$base, $bits] = explode('/', $cidr);
                $net = ip2long($base) & (-1 << (32 - (int) $bits));
                if ((ip2long($ip) & (-1 << (32 - (int) $bits))) === $net) {
                    return true;
                }
            }

            return false;
        }

        return str_starts_with($ip, 'fc') || str_starts_with($ip, 'fd')
            || str_starts_with($ip, 'fe8') || str_starts_with($ip, 'fe9')
            || str_starts_with($ip, 'fea') || str_starts_with($ip, 'feb')
            || str_starts_with($ip, '::1') || str_starts_with($ip, '::');
    }

    /**
     * @return string|null raw body
     */
    private function httpGet(string $url): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => self::FETCH_TIMEOUT,
                'follow_location' => 1,
                'max_redirects' => 3,
                'user_agent' => 'MemoryDown/1.0',
                'header' => "Accept: application/json\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $ctx);

        return is_string($body) ? $body : null;
    }

    /**
     * @param array<string, mixed> $doc
     *
     * @return array{client_id: string, client_name: string, redirect_uris: list<string>, token_endpoint_auth_method: string}|null
     */
    private function validate(array $doc, string $expectedClientId): ?array
    {
        $clientId = $doc['client_id'] ?? null;
        if (!is_string($clientId) || $clientId !== $expectedClientId) {
            return null;
        }

        $clientName = $doc['client_name'] ?? null;
        if (!is_string($clientName) || '' === $clientName) {
            return null;
        }

        $redirectUris = $doc['redirect_uris'] ?? null;
        if (!is_array($redirectUris) || [] === $redirectUris) {
            return null;
        }

        $uris = [];
        foreach ($redirectUris as $uri) {
            if (!is_string($uri) || !$this->isValidRedirectUri($uri)) {
                return null;
            }
            $uris[] = $uri;
        }

        $authMethod = 'none';
        if (isset($doc['token_endpoint_auth_method']) && is_string($doc['token_endpoint_auth_method'])) {
            $authMethod = $doc['token_endpoint_auth_method'];
        }
        if ('none' !== $authMethod) {
            // V1 supports public clients (PKCE) only.
            return null;
        }

        return [
            'client_id' => $clientId,
            'client_name' => $clientName,
            'redirect_uris' => array_values(array_unique($uris)),
            'token_endpoint_auth_method' => 'none',
        ];
    }

    /**
     * Redirect URIs must be https, or http on loopback (RFC 8252).
     */
    public static function isValidRedirectUri(string $uri): bool
    {
        $parsed = parse_url($uri);
        if (!is_array($parsed) || !isset($parsed['scheme'], $parsed['host'])) {
            return false;
        }

        if ('https' === strtolower((string) $parsed['scheme'])) {
            return true;
        }

        if ('http' === strtolower((string) $parsed['scheme'])) {
            $host = strtolower((string) $parsed['host']);

            return 'localhost' === $host || '127.0.0.1' === $host || '[::1]' === $host;
        }

        return false;
    }
}
