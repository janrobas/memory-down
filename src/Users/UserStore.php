<?php

declare(strict_types=1);

namespace MemoryDown\Users;

use MemoryDown\Support\JsonStore;

/**
 * The account store: one JSON file, data/auth/users.json, keyed by user id.
 *
 * Credentials live here rather than in the memory tree on purpose. data/auth/
 * is never web-readable and never part of a backup download, so a password
 * hash or an API token can never travel inside an archive of somebody's
 * memories. The Markdown files stay the only source of truth for memory, and
 * this file is the only source of truth for who may read whose memory.
 *
 * Every mutation goes through JsonStore (flock-protected read-modify-write), so
 * two concurrent requests cannot lose each other's changes — the same
 * discipline the OAuth token store already uses.
 *
 * A handful of invariants are enforced here rather than in the UI, because they
 * are about not locking the instance out of itself:
 *
 *  - an id must be a safe directory name (it becomes one);
 *  - the last enabled admin can be neither deleted, disabled nor demoted.
 */
final class UserStore
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_USER = 'user';

    /** @var list<string> */
    public const ROLES = [self::ROLE_ADMIN, self::ROLE_USER];

    /** Kinds of API token a user can hold (see the read-only publishing APIs). */
    public const TOKEN_WRITINGS = 'writings';
    public const TOKEN_WORKFLOWS = 'workflows';

    /** @var list<string> */
    public const TOKENS = [self::TOKEN_WRITINGS, self::TOKEN_WORKFLOWS];

    /**
     * Allowed user ids. Deliberately stricter than the memory-category pattern:
     * an id is a directory name AND a login name, it is typed by a human, and a
     * leading dot or dash would let it collide with the .trash directory next to
     * the user directories.
     */
    public const ID_PATTERN = '/^[a-z0-9][a-z0-9_-]{2,31}$/';

    public const MIN_PASSWORD_LENGTH = 8;

    /** Guards against hashing an absurd input; no sane password is this long. */
    private const MAX_PASSWORD_LENGTH = 4096;

    public function __construct(private readonly JsonStore $store)
    {
    }

    /**
     * The store that lives beside the OAuth clients and tokens.
     */
    public static function at(string $authDataDir): self
    {
        return new self(new JsonStore(rtrim($authDataDir, '/\\') . '/users.json'));
    }

    public function path(): string
    {
        return $this->store->path();
    }

    public static function isValidId(string $id): bool
    {
        return 1 === preg_match(self::ID_PATTERN, $id);
    }

    /**
     * Every account, keyed by id and sorted by id.
     *
     * @return array<string, User>
     */
    public function all(): array
    {
        $users = [];
        foreach ($this->store->read() as $id => $record) {
            if (!is_string($id) || !is_array($record)) {
                continue;
            }
            $user = User::fromRecord($id, $record);
            if (null !== $user) {
                $users[$id] = $user;
            }
        }
        ksort($users);

        return $users;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->all());
    }

    public function count(): int
    {
        return count($this->all());
    }

    /**
     * Whether any account exists at all. False means the instance has not been
     * set up yet, which is what sends the admin UI to /ui/setup.
     */
    public function isEmpty(): bool
    {
        return [] === $this->store->read();
    }

    public function has(string $id): bool
    {
        return null !== $this->get($id);
    }

    public function get(string $id): ?User
    {
        if (!self::isValidId($id)) {
            return null;
        }
        $record = $this->store->get($id);

        return null === $record ? null : User::fromRecord($id, $record);
    }

    public function enabledAdmins(): int
    {
        $count = 0;
        foreach ($this->all() as $user) {
            if ($user->isAdmin() && $user->enabled) {
                ++$count;
            }
        }

        return $count;
    }

    /* ------------------------------------------------------------------ *
     *  Mutations
     * ------------------------------------------------------------------ */

    public function create(string $id, string $password, string $role = self::ROLE_USER): User
    {
        self::assertId($id);
        self::assertRole($role);
        self::assertPassword($password);

        return $this->insert($id, password_hash($password, PASSWORD_DEFAULT), $role);
    }

    /**
     * Create an account from an existing hash, for the two cases where no
     * plaintext password is available: seeding from the environment on a
     * single-user instance being upgraded, and nothing else.
     */
    public function createWithHash(string $id, string $hash, string $role = self::ROLE_USER): User
    {
        self::assertId($id);
        self::assertRole($role);
        if ('' === trim($hash)) {
            throw new \InvalidArgumentException('A password hash is required.');
        }

        return $this->insert($id, $hash, $role);
    }

    public function setPassword(string $id, string $password): void
    {
        self::assertPassword($password);
        $this->change($id, static function (array $record) use ($password): array {
            $record['password_hash'] = password_hash($password, PASSWORD_DEFAULT);

            return $record;
        });
    }

    public function setRole(string $id, string $role): void
    {
        self::assertRole($role);
        $current = $this->require($id);
        if (UserStore::ROLE_ADMIN === $current->role && self::ROLE_ADMIN !== $role) {
            $this->refuseLastAdmin($current, 'demote');
        }
        $this->change($id, static function (array $record) use ($role): array {
            $record['role'] = $role;

            return $record;
        });
    }

    public function setEnabled(string $id, bool $enabled): void
    {
        $current = $this->require($id);
        if (!$enabled) {
            $this->refuseLastAdmin($current, 'disable');
        }
        // Disabling bumps the epoch like a password change: the sessions and
        // tokens of that account stop working the moment it happens, and
        // re-enabling does not silently revive them.
        $this->change($id, static function (array $record) use ($enabled): array {
            $record['enabled'] = $enabled;

            return $record;
        });
    }

    public function delete(string $id): User
    {
        $current = $this->require($id);
        $this->refuseLastAdmin($current, 'delete');
        $this->store->mutate(static function (array $data) use ($id): array {
            unset($data[$id]);

            return $data;
        });

        return $current;
    }

    /**
     * Give a user an API token. The value is generated here so callers can
     * never pick a weak one, and it is returned so the UI can show it.
     */
    public function rotateToken(string $id, string $kind): string
    {
        $this->assertTokenKind($kind);
        $token = bin2hex(random_bytes(24));
        $this->change($id, static function (array $record) use ($kind, $token): array {
            $tokens = is_array($record['tokens'] ?? null) ? $record['tokens'] : [];
            $tokens[$kind] = $token;
            $record['tokens'] = $tokens;

            return $record;
        }, revoke: false);

        return $token;
    }

    public function clearToken(string $id, string $kind): void
    {
        $this->assertTokenKind($kind);
        $this->change($id, static function (array $record) use ($kind): array {
            $tokens = is_array($record['tokens'] ?? null) ? $record['tokens'] : [];
            unset($tokens[$kind]);
            $record['tokens'] = $tokens;

            return $record;
        }, revoke: false);
    }

    /* ------------------------------------------------------------------ *
     *  Verification
     * ------------------------------------------------------------------ */

    /**
     * Check a password. Returns the account when the password is right,
     * regardless of whether it is enabled: the caller decides what to say, so
     * the UI can tell a disabled user "this account is disabled" instead of
     * pretending the password was wrong (that reveals nothing — they already
     * knew the password).
     */
    public function verify(string $id, string $password): ?User
    {
        $user = $this->get($id);
        if (null === $user || '' === $password) {
            return null;
        }

        return password_verify($password, $user->passwordHash) ? $user : null;
    }

    /**
     * Find the enabled user that owns an API token, or null.
     *
     * Every candidate is compared even after a match, so the time taken does
     * not depend on which user (or whether any user) matched.
     */
    public function verifyToken(string $kind, string $token): ?User
    {
        if ('' === $token || !in_array($kind, self::TOKENS, true)) {
            return null;
        }

        $found = null;
        foreach ($this->all() as $user) {
            $expected = $user->token($kind);
            if (hash_equals($expected, $token) && $user->enabled) {
                $found = $user;
            }
        }

        return $found;
    }

    /* ------------------------------------------------------------------ *
     *  Internals
     * ------------------------------------------------------------------ */

    private function insert(string $id, string $hash, string $role): User
    {
        $now = time();
        $user = new User($id, $role, $hash, true, 1, $now, $now, []);

        $this->store->mutate(static function (array $data) use ($id, $user): array {
            if (isset($data[$id])) {
                throw new \InvalidArgumentException(sprintf('A user named "%s" already exists.', $id));
            }
            $data[$id] = $user->record();

            return $data;
        });

        return $user;
    }

    /**
     * Apply a change to one record and stamp updated_at.
     *
     * $revoke asks for an auth-epoch bump, which is what invalidates the
     * account's live sessions and OAuth tokens. Anything that changes what the
     * account *is* (its password, its role, whether it is enabled) asks for it;
     * rotating a publishing API token does not, because that token guards a
     * read-only export of the writings or workflows category and has nothing to
     * do with the MCP session someone is in the middle of.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $change
     */
    private function change(string $id, callable $change, bool $revoke = true): void
    {
        self::assertId($id);
        $this->store->mutate(static function (array $data) use ($id, $change, $revoke): array {
            $record = $data[$id] ?? null;
            if (!is_array($record)) {
                throw new \InvalidArgumentException(sprintf('No such user: %s', $id));
            }
            $record = $change($record);
            if ($revoke) {
                $record['auth_epoch'] = (int) ($record['auth_epoch'] ?? 0) + 1;
            }
            $record['updated_at'] = time();
            $data[$id] = $record;

            return $data;
        });
    }

    private function require(string $id): User
    {
        $user = $this->get($id);
        if (null === $user) {
            throw new \InvalidArgumentException(sprintf('No such user: %s', $id));
        }

        return $user;
    }

    /**
     * Keep at least one enabled admin: without one, nobody could manage users
     * (or create the one that could) ever again, and the only way back would be
     * editing data/auth/users.json by hand over FTP.
     */
    private function refuseLastAdmin(User $user, string $action): void
    {
        if ($user->isAdmin() && $user->enabled && $this->enabledAdmins() <= 1) {
            throw new \InvalidArgumentException(
                sprintf('Cannot %s "%s": it is the only enabled admin. Make someone else an admin first.', $action, $user->id)
            );
        }
    }

    public static function assertId(string $id): void
    {
        if (!self::isValidId($id)) {
            throw new \InvalidArgumentException(
                'A username must be 3-32 characters of a-z, 0-9, "_" or "-", and start with a letter or digit.'
            );
        }
    }

    public static function assertRole(string $role): void
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException('Unknown role: ' . $role);
        }
    }

    public static function assertPassword(string $password): void
    {
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new \InvalidArgumentException('Choose a password of at least ' . self::MIN_PASSWORD_LENGTH . ' characters.');
        }
        if (strlen($password) > self::MAX_PASSWORD_LENGTH) {
            throw new \InvalidArgumentException('That password is too long.');
        }
    }

    private static function assertTokenKind(string $kind): void
    {
        if (!in_array($kind, self::TOKENS, true)) {
            throw new \InvalidArgumentException('Unknown token kind: ' . $kind);
        }
    }
}
