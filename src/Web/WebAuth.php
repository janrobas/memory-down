<?php

declare(strict_types=1);

namespace MemoryDown\Web;

use MemoryDown\Config;
use MemoryDown\Users\User;
use MemoryDown\Users\UserStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Session-based authentication for the admin UI.
 *
 * One credential per person: the same username and password open the UI and the
 * OAuth consent page (see Auth\AuthorizationServer), checked against the same
 * account record. There is no separate admin password and no environment
 * override — an account holds a one-way hash, and changing it in the UI takes
 * effect immediately.
 *
 * The session carries the account id and the auth epoch it signed in under. The
 * epoch is compared on every request, so a password change, a role change or
 * disabling the account invalidates live sessions without scanning session
 * files — which is what makes "that account's sessions stopped working" true the
 * moment an admin does it, on shared hosting with no daemon to run.
 *
 * Plaintext passwords are never persisted and never logged.
 */
final class WebAuth
{
    private const SESSION_NAME = 'memorydown_admin';
    private const MAX_FAILURES = 5;
    private const LOCK_SECONDS = 30;

    private bool $started = false;
    private bool $resolved = false;
    private ?User $user = null;

    public function __construct(
        private readonly Config $config,
        private readonly UserStore $users,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function start(): void
    {
        if ($this->started || \PHP_SESSION_ACTIVE === session_status()) {
            $this->started = true;

            return;
        }

        $sessionDir = $this->config->dataPath . '/sessions';
        if (is_dir($sessionDir)) {
            session_save_path($sessionDir);
        }

        session_name(self::SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'secure' => $this->config->isProduction(),
            'samesite' => 'Lax',
        ]);
        session_start();
        $this->started = true;
    }

    /**
     * Whether any account exists yet. Until one does, the UI is a one-time
     * setup form and nothing else.
     */
    public function isConfigured(): bool
    {
        return !$this->users->isEmpty();
    }

    public function users(): UserStore
    {
        return $this->users;
    }

    public function currentUser(): ?User
    {
        $this->start();
        if ($this->resolved) {
            return $this->user;
        }
        $this->resolved = true;
        $this->user = null;

        $id = $_SESSION['user_id'] ?? null;
        if (!is_string($id) || '' === $id) {
            return null;
        }

        $user = $this->users->get($id);
        $epoch = (int) ($_SESSION['auth_epoch'] ?? 0);
        if (null === $user || !$user->enabled || $user->authEpoch !== $epoch) {
            // Gone, disabled, or changed underneath us. The cookie is no longer
            // proof of anything, so it is dropped rather than left to be retried.
            unset($_SESSION['user_id'], $_SESSION['auth_epoch']);
            $this->logger->info('admin.session_invalidated', ['user' => $id]);

            return null;
        }

        return $this->user = $user;
    }

    public function loggedIn(): bool
    {
        return null !== $this->currentUser();
    }

    public function isAdmin(): bool
    {
        return (bool) $this->currentUser()?->isAdmin();
    }

    /**
     * Try to sign in. Returns '' on success, otherwise a message safe to show
     * the person typing: it never distinguishes a wrong username from a wrong
     * password, but it does say when an account is disabled — somebody who got
     * the password right is not being told a secret.
     */
    public function attempt(string $username, string $password): string
    {
        $this->start();

        if ((int) ($_SESSION['locked_until'] ?? 0) > time()) {
            return 'Too many attempts. Wait a moment and try again.';
        }

        $username = strtolower(trim($username));
        $user = '' === $username ? null : $this->users->verify($username, $password);

        if (null === $user) {
            $failures = (int) ($_SESSION['login_failures'] ?? 0) + 1;
            $_SESSION['login_failures'] = $failures;
            if ($failures >= self::MAX_FAILURES) {
                $_SESSION['locked_until'] = time() + self::LOCK_SECONDS;
                $_SESSION['login_failures'] = 0;
            }
            $this->logger->warning('admin.login_failed', ['user' => $username]);

            return 'Incorrect username or password.';
        }

        if (!$user->enabled) {
            $this->logger->warning('admin.login_disabled', ['user' => $user->id]);

            return 'That account is disabled.';
        }

        session_regenerate_id(true);
        $_SESSION['login_failures'] = 0;
        unset($_SESSION['locked_until']);
        $this->remember($user);
        $this->logger->info('admin.login_ok', ['user' => $user->id]);

        return '';
    }

    /**
     * Make the session act as this account, at its current epoch.
     *
     * Used after a login, and after a user changes their own password or role:
     * the change bumps the epoch, and without this the person who just made it
     * would be signed out by their own action.
     */
    public function remember(User $user): void
    {
        $this->start();
        $_SESSION['user_id'] = $user->id;
        $_SESSION['auth_epoch'] = $user->authEpoch;
        $this->user = $user;
        $this->resolved = true;
    }

    public function logout(): void
    {
        $this->start();
        $id = $this->user?->id ?? ($_SESSION['user_id'] ?? null);
        $_SESSION = [];
        if (\PHP_SESSION_ACTIVE === session_status()) {
            session_destroy();
        }
        $this->user = null;
        $this->resolved = true;
        $this->logger->info('admin.logout', ['user' => is_string($id) ? $id : '']);
    }

    public function csrfToken(): string
    {
        $this->start();
        if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf']) || '' === $_SESSION['csrf']) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf'];
    }

    public function checkCsrf(?string $token): bool
    {
        $this->start();
        $expected = $_SESSION['csrf'] ?? '';

        return is_string($expected) && '' !== $expected && is_string($token) && hash_equals($expected, $token);
    }
}
