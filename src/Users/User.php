<?php

declare(strict_types=1);

namespace MemoryDown\Users;

/**
 * One account, as stored in data/auth/users.json.
 *
 * A user is the unit of isolation. The id names the directory their Markdown
 * lives in (data/users/<id>/memory) and it is the subject every credential
 * refers to:
 *
 *  - the password opens BOTH the admin UI and the OAuth consent page, so there
 *    is one secret per person instead of an admin password plus a separate
 *    consent password;
 *  - the API tokens are the per-user keys of the read-only publishing APIs;
 *  - `sub` in every issued OAuth token is this id, which is how a bearer token
 *    on /mcp is resolved back to the corpus it may touch.
 *
 * The record is deliberately plain: a one-way hash, never a password. The API
 * tokens are the one value kept in the clear, because the admin UI has to be
 * able to show them again to the person who pastes them into a website — the
 * same exposure these tokens had in .env before they moved here, and
 * data/auth/ is never web-readable.
 *
 * `authEpoch` is bumped whenever a credential changes or the account is
 * disabled. Sessions and OAuth tokens remember the epoch they were created
 * under, so "log this user out everywhere" is one integer rather than a sweep
 * over session files and token records.
 */
final class User
{
    /**
     * @param array<string, string> $tokens API token per UserStore::TOKENS kind
     */
    public function __construct(
        public readonly string $id,
        public readonly string $role,
        public readonly string $passwordHash,
        public readonly bool $enabled,
        public readonly int $authEpoch,
        public readonly int $createdAt,
        public readonly int $updatedAt,
        public readonly array $tokens = [],
    ) {
    }

    /**
     * Build from the stored record. Returns null for a record that cannot be
     * used at all (no password hash), so a mangled file degrades to "no such
     * user" rather than to a user nobody can authenticate.
     *
     * @param array<string, mixed> $record
     */
    public static function fromRecord(string $id, array $record): ?self
    {
        $hash = $record['password_hash'] ?? null;
        if (!is_string($hash) || '' === $hash) {
            return null;
        }

        $role = (string) ($record['role'] ?? UserStore::ROLE_USER);
        $stored = $record['tokens'] ?? [];
        $tokens = [];
        if (is_array($stored)) {
            foreach (UserStore::TOKENS as $kind) {
                $value = $stored[$kind] ?? null;
                if (is_string($value) && '' !== $value) {
                    $tokens[$kind] = $value;
                }
            }
        }

        return new self(
            $id,
            in_array($role, UserStore::ROLES, true) ? $role : UserStore::ROLE_USER,
            $hash,
            (bool) ($record['enabled'] ?? true),
            (int) ($record['auth_epoch'] ?? 0),
            (int) ($record['created_at'] ?? 0),
            (int) ($record['updated_at'] ?? 0),
            $tokens,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function record(): array
    {
        return [
            'role' => $this->role,
            'password_hash' => $this->passwordHash,
            'enabled' => $this->enabled,
            'auth_epoch' => $this->authEpoch,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'tokens' => $this->tokens,
        ];
    }

    public function isAdmin(): bool
    {
        return UserStore::ROLE_ADMIN === $this->role;
    }

    /** The API token of $kind, or '' when this user has none. */
    public function token(string $kind): string
    {
        return $this->tokens[$kind] ?? '';
    }

    public function hasToken(string $kind): bool
    {
        return '' !== $this->token($kind);
    }

    /** Whether any API token is set at all (for the users list). */
    public function hasAnyToken(): bool
    {
        foreach (UserStore::TOKENS as $kind) {
            if ($this->hasToken($kind)) {
                return true;
            }
        }

        return false;
    }
}
