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
 * that was requested during authorization. They are also subject-bound: `sub`
 * is the account that consented, and `epoch` is that account's auth epoch at
 * the moment of issue. The epoch is what makes "log this account out
 * everywhere" a single field: a token whose epoch no longer matches the
 * account's is refused, without walking the token file.
 *
 * One file serves the whole instance, because a token is minted for a client
 * (which may be shared) acting for one account — the accounts are told apart by
 * `sub`, not by which file their tokens happen to be in.
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
        string $sub,
        int $epoch,
    ): string {
        $code = self::randomToken();
        $this->store->put('code_' . self::hash($code), [
            'type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'scope' => $scopes,
            'resource' => $resource,
            'sub' => $sub,
            'epoch' => $epoch,
            'created_at' => time(),
            'expires_at' => time() + $this->codeTtl,
        ]);
        $this->gc();

        return $code;
    }

    /**
     * Atomically consume a single-use authorization code.
     *
     * @return array{client_id: string, redirect_uri: string, code_challenge: string, scope: string[], resource: string, sub: string, epoch: int}|null
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
            'sub' => (string) ($record['sub'] ?? ''),
            'epoch' => (int) ($record['epoch'] ?? 0),
        ];
    }

    /**
     * @param string[] $scopes
     */
    public function createAccessToken(string $clientId, array $scopes, string $resource, string $sub, int $epoch): string
    {
        $token = self::randomToken();
        $this->store->put('access_' . self::hash($token), [
            'type' => 'access',
            'client_id' => $clientId,
            'scope' => $scopes,
            'resource' => $resource,
            'sub' => $sub,
            'epoch' => $epoch,
            'created_at' => time(),
            'expires_at' => time() + $this->accessTtl,
        ]);
        $this->gc();

        return $token;
    }

    /**
     * @return array{client_id: string, scope: string[], resource: string, sub: string, epoch: int}|null
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
            'sub' => (string) ($record['sub'] ?? ''),
            'epoch' => (int) ($record['epoch'] ?? 0),
        ];
    }

    /**
     * @param string[] $scopes
     */
    public function createRefreshToken(string $clientId, array $scopes, string $resource, string $sub, int $epoch): string
    {
        $token = self::randomToken();
        $this->store->put('refresh_' . self::hash($token), [
            'type' => 'refresh',
            'client_id' => $clientId,
            'scope' => $scopes,
            'resource' => $resource,
            'sub' => $sub,
            'epoch' => $epoch,
            'created_at' => time(),
            'expires_at' => time() + $this->refreshTtl,
        ]);
        $this->gc();

        return $token;
    }

    /**
     * Set when the most recent {@see rotateRefreshToken} call recognized a
     * replay of a token that was already rotated: the presented token's whole
     * family has been revoked (OAuth 2.1 theft detection), and the rotation
     * still reports failure. The flag lets the token endpoint log the event at
     * a higher severity without changing the response — the response must stay
     * a plain invalid_grant, because saying "this was a reused token" is
     * information only the thief needs.
     */
    public bool $refreshReuseDetected = false;

    /**
     * Atomically consume a refresh token and issue its replacement
     * (OAuth 2.1 refresh-token rotation for public clients).
     *
     * Presenting a token that was already consumed is treated as theft: the
     * replacement it produced — and every replacement that came after it — is
     * revoked on the spot, so the whole family dies with the replayed token.
     *
     * @return array{refresh_token: string, client_id: string, scope: string[], resource: string, sub: string, epoch: int}|null
     */
    public function rotateRefreshToken(string $refreshToken): ?array
    {
        $this->refreshReuseDetected = false;

        $oldKey = 'refresh_' . self::hash($refreshToken);
        $usedKey = 'used_' . self::hash($refreshToken);
        $record = null;
        $newToken = '';

        $this->store->mutate(function (array $data) use ($oldKey, $usedKey, &$record, &$newToken): array {
            if (isset($data[$usedKey]) && is_array($data[$usedKey])) {
                // A token that was already rotated is presented again: the
                // token (or its family) may have been stolen. Revoke every
                // replacement it ever produced, so the family dies with it.
                $replacedBy = (string) ($data[$usedKey]['replaced_by'] ?? '');
                unset($data[$usedKey]);
                if ('' !== $replacedBy) {
                    $data = $this->revokeRefreshFamily($data, $replacedBy);
                }
                $this->refreshReuseDetected = true;

                return $data;
            }

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
            $newHash = self::hash($newToken);
            $data['refresh_' . $newHash] = [
                'type' => 'refresh',
                'client_id' => (string) ($entry['client_id'] ?? ''),
                'scope' => is_array($entry['scope'] ?? null) ? $entry['scope'] : [],
                'resource' => (string) ($entry['resource'] ?? ''),
                'sub' => (string) ($entry['sub'] ?? ''),
                'epoch' => (int) ($entry['epoch'] ?? 0),
                'created_at' => time(),
                'expires_at' => time() + $this->refreshTtl,
            ];
            // The tombstone makes a replay of the consumed token detectable,
            // and points at the replacement so the family can be revoked.
            $data[$usedKey] = [
                'type' => 'used_refresh',
                'replaced_by' => $newHash,
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
            'sub' => (string) ($record['sub'] ?? ''),
            'epoch' => (int) ($record['epoch'] ?? 0),
        ];
    }

    /**
     * Revoke a refresh-token family from $firstHash onward, following the
     * "replaced_by" tombstones each rotation leaves behind.
     *
     * The walk is bounded so a corrupt store can never loop; anything beyond
     * the bound is left to expire on its own.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function revokeRefreshFamily(array $data, string $firstHash): array
    {
        $next = $firstHash;
        for ($i = 0; $i < 32 && '' !== $next; ++$i) {
            $replacedBy = (string) ($data['used_' . $next]['replaced_by'] ?? '');
            unset($data['refresh_' . $next], $data['used_' . $next]);
            $next = $replacedBy;
        }

        return $data;
    }

    /**
     * @return bool whether a live access token of that value existed
     */
    public function revokeAccessToken(string $token): bool
    {
        $key = 'access_' . self::hash($token);
        $found = false;
        $this->store->mutate(static function (array $data) use ($key, &$found): array {
            if (isset($data[$key])) {
                unset($data[$key]);
                $found = true;
            }

            return $data;
        });

        return $found;
    }

    /**
     * Revoke a refresh token and its whole family (every replacement a
     * rotation of it ever produced).
     *
     * @return bool whether a live refresh token of that value existed
     */
    public function revokeRefreshToken(string $token): bool
    {
        $hash = self::hash($token);
        $found = false;
        $this->store->mutate(function (array $data) use ($hash, &$found): array {
            if (!isset($data['refresh_' . $hash]) && !isset($data['used_' . $hash])) {
                return $data;
            }
            // Whether a live token existed, even though a dangling tombstone
            // (nothing but a rotation marker left) is swept just the same.
            $found = isset($data['refresh_' . $hash]);

            return $this->revokeRefreshFamily($data, $hash);
        });

        return $found;
    }

    /**
     * Drop every code, access token and refresh token belonging to an account.
     *
     * Used when an account is deleted, where there is no epoch left to bump:
     * without this the tokens would simply stop resolving a user and be refused,
     * but they would keep sitting in the file until they expired.
     *
     * @return int how many records were removed
     */
    public function revokeAllForUser(string $sub): int
    {
        if ('' === $sub) {
            return 0;
        }

        $removed = 0;
        $this->store->mutate(static function (array $data) use ($sub, &$removed): array {
            foreach ($data as $key => $record) {
                if (is_array($record) && $sub === (string) ($record['sub'] ?? '')) {
                    unset($data[$key]);
                    ++$removed;
                }
            }

            return $data;
        });

        return $removed;
    }

    /** How many records exist (diagnostics only; never exposes a token). */
    public function count(): int
    {
        return count($this->store->read());
    }

    private function gc(): void
    {
        if (random_int(1, 100) <= 25) {
            $this->store->gc(time() - $this->refreshTtl);
        }
    }
}
