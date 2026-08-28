<?php

declare(strict_types=1);

namespace MemoryDown\Auth;

use MemoryDown\Support\JsonStore;

/**
 * File-backed store for OAuth authorization codes, access tokens and refresh
 * tokens. Token values are random 256-bit strings; only their SHA-256 hashes
 * are persisted, so a leaked data/auth directory does not leak usable tokens.
 *
 * Access tokens are audience-bound: each token records the `resource` value
 * that was requested during authorization.
 */
final class TokenStore
{
    public const SCOPE = 'memory:all';

    private const HASH_ALGO = 'sha256';

    private JsonStore $store;

    public function __construct(
        string $authDataDir,
        private readonly int $accessTtl,
        private readonly int $refreshTtl,
        private readonly int $codeTtl,
    ) {
        $this->store = new JsonStore($authDataDir . '/tokens.json');
    }

    public static function randomToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hash(string $token): string
    {
        return hash(self::HASH_ALGO, $token);
    }

    /**
     * @param string[] $scopes
     */
    public function createCode(
        string $clientId,
        string $redirectUri,
        string $codeChallenge,
        array $scopes,
        string $resource,
    ): string {
        $code = self::randomToken();
        $this->store->put('code_' . self::hash($code), [
            'type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'scope' => $scopes,
            'resource' => $resource,
            'created_at' => time(),
            'expires_at' => time() + $this->codeTtl,
        ]);
        $this->gc();

        return $code;
    }

    /**
     * Atomically consume a single-use authorization code.
     *
     * @return array{client_id: string, redirect_uri: string, code_challenge: string, scope: string[], resource: string}|null
     */
    public function consumeCode(string $code): ?array
    {
        $key = 'code_' . self::hash($code);
        $record = null;

        $this->store->mutate(function (array $data) use ($key, &$record): array {
            if (!isset($data[$key]) || !is_array($data[$key])) {
                return $data;
            }
            $entry = $data[$key];
            unset($data[$key]);
            $record = $entry;

            return $data;
        });

        if (null === $record) {
            return null;
        }
        if (($record['expires_at'] ?? 0) < time()) {
            return null;
        }

        return [
            'client_id' => (string) ($record['client_id'] ?? ''),
            'redirect_uri' => (string) ($record['redirect_uri'] ?? ''),
            'code_challenge' => (string) ($record['code_challenge'] ?? ''),
            'scope' => is_array($record['scope'] ?? null) ? array_values(array_map('strval', $record['scope'])) : [],
            'resource' => (string) ($record['resource'] ?? ''),
        ];
    }

    /**
     * @param string[] $scopes
     */
    public function createAccessToken(string $clientId, array $scopes, string $resource): string
    {
        $token = self::randomToken();
        $this->store->put('access_' . self::hash($token), [
            'type' => 'access',
            'client_id' => $clientId,
            'scope' => $scopes,
            'resource' => $resource,
            'created_at' => time(),
            'expires_at' => time() + $this->accessTtl,
        ]);
        $this->gc();

        return $token;
    }

    /**
     * @return array{client_id: string, scope: string[], resource: string}|null
     */
    public function validateAccessToken(string $token): ?array
    {
        $record = $this->store->get('access_' . self::hash($token));
        if (null === $record) {
            return null;
        }
        if (($record['expires_at'] ?? 0) < time()) {
            $this->store->remove('access_' . self::hash($token));

            return null;
        }

        return [
            'client_id' => (string) ($record['client_id'] ?? ''),
            'scope' => is_array($record['scope'] ?? null) ? array_values(array_map('strval', $record['scope'])) : [],
            'resource' => (string) ($record['resource'] ?? ''),
        ];
    }

    /**
     * @param string[] $scopes
     */
    public function createRefreshToken(string $clientId, array $scopes, string $resource): string
    {
        $token = self::randomToken();
        $this->store->put('refresh_' . self::hash($token), [
            'type' => 'refresh',
            'client_id' => $clientId,
            'scope' => $scopes,
            'resource' => $resource,
            'created_at' => time(),
            'expires_at' => time() + $this->refreshTtl,
        ]);
        $this->gc();

        return $token;
    }

    /**
     * Atomically consume a refresh token and issue its replacement
     * (OAuth 2.1 refresh-token rotation for public clients).
     *
     * @return array{refresh_token: string, client_id: string, scope: string[], resource: string}|null
     */
    public function rotateRefreshToken(string $refreshToken): ?array
    {
        $oldKey = 'refresh_' . self::hash($refreshToken);
        $record = null;
        $newToken = '';

        $this->store->mutate(function (array $data) use ($oldKey, &$record, &$newToken): array {
            if (!isset($data[$oldKey]) || !is_array($data[$oldKey])) {
                return $data;
            }
            $entry = $data[$oldKey];
            if (($entry['expires_at'] ?? 0) < time()) {
                unset($data[$oldKey]);

                return $data;
            }
            unset($data[$oldKey]);
            $record = $entry;

            $newToken = self::randomToken();
            $data['refresh_' . self::hash($newToken)] = [
                'type' => 'refresh',
                'client_id' => (string) ($entry['client_id'] ?? ''),
                'scope' => is_array($entry['scope'] ?? null) ? $entry['scope'] : [],
                'resource' => (string) ($entry['resource'] ?? ''),
                'created_at' => time(),
                'expires_at' => time() + $this->refreshTtl,
            ];

            return $data;
        });

        if (null === $record || '' === $newToken) {
            return null;
        }

        return [
            'refresh_token' => $newToken,
            'client_id' => (string) ($record['client_id'] ?? ''),
            'scope' => is_array($record['scope'] ?? null) ? array_values(array_map('strval', $record['scope'])) : [],
            'resource' => (string) ($record['resource'] ?? ''),
        ];
    }

    public function revokeAccessToken(string $token): void
    {
        $this->store->remove('access_' . self::hash($token));
    }

    public function revokeRefreshToken(string $token): void
    {
        $this->store->remove('refresh_' . self::hash($token));
    }

    private function gc(): void
    {
        if (random_int(1, 100) <= 25) {
            $this->store->gc(time() - $this->refreshTtl);
        }
    }
}
