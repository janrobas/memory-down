<?php

declare(strict_types=1);

namespace MemoryDown\Web;

use MemoryDown\Config;
use MemoryDown\Support\JsonStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Session-based authentication for the admin UI.
 *
 * This is deliberately separate from the MCP OAuth layer: the UI is for the
 * single human owner of the instance, not an MCP client. The password is a
 * bcrypt/argon hash, supplied via ADMIN_PASSWORD_HASH or (on first run) set
 * through /ui/setup, which stores it in data/auth/ui.json. Plaintext
 * passwords are never persisted and never logged.
 */
final class WebAuth
{
    private const SESSION_NAME = 'memorydown_admin';
    private const MAX_FAILURES = 5;
    private const LOCK_SECONDS = 30;

    private JsonStore $store;
    private bool $started = false;

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->store = new JsonStore($config->dataPath . '/auth/ui.json');
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
     * Whether an admin password has been configured (env hash or setup file).
     */
    public function isConfigured(): bool
    {
        return $this->passwordHash() !== null;
    }

    public function setPassword(string $plain): void
    {
        $this->store->put('admin', [
            'password_hash' => password_hash($plain, PASSWORD_DEFAULT),
            'updated_at' => time(),
        ]);
        $this->logger->info('admin.password_set');
    }

    public function verify(string $plain): bool
    {
        $hash = $this->passwordHash();

        return null !== $hash && password_verify($plain, $hash);
    }

    public function loggedIn(): bool
    {
        $this->start();

        return true === ($_SESSION['authenticated'] ?? false);
    }

    public function login(string $plain): bool
    {
        $this->start();

        $lockedUntil = (int) ($_SESSION['locked_until'] ?? 0);
        if ($lockedUntil > time()) {
            return false;
        }

        if (!$this->verify($plain)) {
            $failures = (int) ($_SESSION['login_failures'] ?? 0) + 1;
            $_SESSION['login_failures'] = $failures;
            if ($failures >= self::MAX_FAILURES) {
                $_SESSION['locked_until'] = time() + self::LOCK_SECONDS;
                $_SESSION['login_failures'] = 0;
            }
            $this->logger->warning('admin.login_failed');

            return false;
        }

        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['login_failures'] = 0;
        unset($_SESSION['locked_until']);
        $this->logger->info('admin.login_ok');

        return true;
    }

    public function logout(): void
    {
        $this->start();
        $_SESSION = [];
        if (\PHP_SESSION_ACTIVE === session_status()) {
            session_destroy();
        }
        $this->logger->info('admin.logout');
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

    private function passwordHash(): ?string
    {
        if ('' !== $this->config->adminPasswordHash) {
            return $this->config->adminPasswordHash;
        }

        $record = $this->store->get('admin');
        $hash = $record['password_hash'] ?? null;

        return is_string($hash) && '' !== $hash ? $hash : null;
    }
}
