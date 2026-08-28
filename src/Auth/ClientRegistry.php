<?php

declare(strict_types=1);

namespace MemoryDown\Auth;

use MemoryDown\Support\JsonStore;
use Psr\Log\LoggerInterface;

/**
 * Resolves OAuth client identities:
 *
 *  - URL-formatted client_ids are resolved through Client ID Metadata
 *    Documents (CIMD) — this is how ChatGPT identifies itself today
 *    (https://chatgpt.com/oauth/client.json).
 *  - dcr_* client_ids come from Dynamic Client Registration (RFC 7591).
 */
final class ClientRegistry
{
    private JsonStore $registered;
    private CimdFetcher $cimd;

    public function __construct(
        string $authDataDir,
        array $cimdAllowedOrigins,
        private readonly LoggerInterface $logger = new \Psr\Log\NullLogger(),
    ) {
        $this->registered = new JsonStore($authDataDir . '/clients.json');
        $this->cimd = new CimdFetcher($authDataDir, $cimdAllowedOrigins, $logger);
    }

    /**
     * @return array{client_id: string, client_name: string, redirect_uris: list<string>, token_endpoint_auth_method: string}|null
     */
    public function resolve(string $clientId): ?array
    {
        if (str_starts_with($clientId, 'https://')) {
            $client = $this->cimd->fetch($clientId);
            if (null !== $client) {
                $this->logger->info('client.cimd_resolved', ['client_id' => $clientId]);
            }

            return $client;
        }

        $record = $this->registered->get($clientId);
        if (null === $record) {
            return null;
        }

        $uris = $record['redirect_uris'] ?? [];
        if (!is_array($uris)) {
            $uris = [];
        }

        return [
            'client_id' => (string) ($record['client_id'] ?? $clientId),
            'client_name' => (string) ($record['client_name'] ?? $clientId),
            'redirect_uris' => array_values(array_map('strval', $uris)),
            'token_endpoint_auth_method' => (string) ($record['token_endpoint_auth_method'] ?? 'none'),
        ];
    }

    /**
     * RFC 7591 dynamic client registration (public clients only in V1).
     *
     * @param array<string, mixed> $payload
     *
     * @return array{client_id: string, client_name: string, redirect_uris: list<string>, grant_types: list<string>, response_types: list<string>, token_endpoint_auth_method: string}
     */
    public function register(array $payload): array
    {
        $clientName = $payload['client_name'] ?? null;
        if (!is_string($clientName) || '' === trim($clientName)) {
            throw new \InvalidArgumentException('client_name is required.');
        }

        $redirectUris = $payload['redirect_uris'] ?? null;
        if (!is_array($redirectUris) || [] === $redirectUris) {
            throw new \InvalidArgumentException('redirect_uris must be a non-empty array.');
        }
        $uris = [];
        foreach ($redirectUris as $uri) {
            if (!is_string($uri) || !CimdFetcher::isValidRedirectUri($uri)) {
                throw new \InvalidArgumentException('redirect_uris must be https (or http on localhost).');
            }
            $uris[] = $uri;
        }

        $authMethod = $payload['token_endpoint_auth_method'] ?? 'none';
        if (!is_string($authMethod) || 'none' !== $authMethod) {
            throw new \InvalidArgumentException('Only token_endpoint_auth_method "none" (public client) is supported.');
        }

        $grantTypes = $payload['grant_types'] ?? ['authorization_code', 'refresh_token'];
        if (!is_array($grantTypes)) {
            $grantTypes = ['authorization_code', 'refresh_token'];
        }

        $responseTypes = $payload['response_types'] ?? ['code'];
        if (!is_array($responseTypes)) {
            $responseTypes = ['code'];
        }

        $clientId = 'dcr_' . bin2hex(random_bytes(8));

        $record = [
            'client_id' => $clientId,
            'client_name' => trim($clientName),
            'redirect_uris' => array_values(array_unique($uris)),
            'token_endpoint_auth_method' => 'none',
        ];
        $this->registered->put($clientId, $record);
        $this->logger->info('client.registered', ['client_id' => $clientId]);

        return [
            'client_id' => $clientId,
            'client_name' => $record['client_name'],
            'redirect_uris' => $record['redirect_uris'],
            'grant_types' => array_values(array_map('strval', $grantTypes)),
            'response_types' => array_values(array_map('strval', $responseTypes)),
            'token_endpoint_auth_method' => 'none',
        ];
    }
}
