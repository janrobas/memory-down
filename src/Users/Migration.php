<?php

declare(strict_types=1);

namespace MemoryDown\Users;

use MemoryDown\Config;
use MemoryDown\Support\JsonStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Turns a single-user instance into a multi-user one, once, on first boot.
 *
 * Before accounts existed, credentials came from the environment
 * (ADMIN_PASSWORD_HASH for the UI, OAUTH_USERNAME + OAUTH_CONSENT_PASSWORD for
 * the OAuth consent page) and there was exactly one memory directory
 * (MEMORY_PATH, by default data/memory). This migration reads those, creates
 * the first admin from them and moves the corpus under that admin's root, so an
 * existing deployment upgrades itself with no manual step and no data loss.
 *
 * It is idempotent and it is the ONLY thing that ever consults the legacy
 * configuration: a seeded admin has an account record like everybody else, and
 * the environment variables are ignored from then on (which is what makes
 * changing a password in the UI actually stick — ADMIN_PASSWORD_HASH used to
 * override the stored hash forever, so a new password silently did nothing).
 *
 * When no credential exists at all — a fresh install — it does nothing, and
 * /ui/setup creates the first admin instead. That is the one place a password
 * is ever typed in the clear, and it is guarded by ADMIN_SETUP_TOKEN.
 *
 * Nothing is ever destroyed: a failed move leaves the account without a corpus
 * rather than with a mangled one, and the old directory is reported rather than
 * silently abandoned.
 */
final class Migration
{
    private JsonStore $marker;

    public function __construct(
        private readonly Config $config,
        private readonly UserStore $users,
        private readonly UserPaths $paths,
        private readonly UserDirectories $directories,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->marker = new JsonStore($config->dataPath . '/auth/migration.json');
    }

    public function run(): void
    {
        if (!$this->users->isEmpty()) {
            return;
        }

        // Seeding is a ONE-SHOT event, recorded in data/auth/migration.json. This
        // runs on every request (the Kernel is built per request), so without the
        // marker a deleted data/auth/users.json would silently resurrect an admin
        // with whatever password the environment still carries — the opposite of
        // what deleting an account should mean. After this, the only way to
        // (re)create an account is /ui/setup, guarded by ADMIN_SETUP_TOKEN.
        if (null !== $this->marker->get('seeded')) {
            return;
        }

        $seed = $this->seed();
        if (null === $seed) {
            return;
        }

        try {
            $this->users->createWithHash($seed['id'], $seed['hash'], UserStore::ROLE_ADMIN);
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('users.migration.failed', ['id' => $seed['id'], 'message' => $e->getMessage()]);

            return;
        }

        $this->marker->put('seeded', [
            'id' => $seed['id'],
            'source' => $seed['source'],
            'at' => time(),
        ]);
        $this->logger->info('users.migration.seeded', ['id' => $seed['id'], 'source' => $seed['source']]);
        $this->adoptMemory($seed['id']);
    }

    /**
     * The first admin, taken from whatever the single-user configuration had.
     *
     * Precedence is deliberate: the admin UI password is the instance owner's
     * identity, so it wins; the consent password only matters when no UI
     * password was ever set. Where both were set to different secrets, the
     * consent password no longer works — consolidating them onto one password
     * per person is the point, and OAUTH_CONSENT_PASSWORD is documented as
     * deprecated seed material.
     *
     * @return array{id: string, hash: string, source: string}|null
     */
    private function seed(): ?array
    {
        $id = strtolower(trim($this->config->oauthUsername));
        if (!UserStore::isValidId($id)) {
            $id = 'admin';
        }

        if ('' !== trim($this->config->adminPasswordHash)) {
            return ['id' => $id, 'hash' => $this->config->adminPasswordHash, 'source' => 'ADMIN_PASSWORD_HASH'];
        }

        // The hash a previous version of the UI stored on first run.
        $legacy = (new JsonStore($this->config->dataPath . '/auth/ui.json'))->get('admin');
        $hash = $legacy['password_hash'] ?? null;
        if (is_string($hash) && '' !== $hash) {
            return ['id' => $id, 'hash' => $hash, 'source' => 'data/auth/ui.json'];
        }

        $consent = $this->config->oauthConsentPassword;
        if ('' !== $consent) {
            return ['id' => $id, 'hash' => self::asHash($consent), 'source' => 'OAUTH_CONSENT_PASSWORD'];
        }

        return null;
    }

    /**
     * A hash is taken as-is; a plaintext secret from .env is hashed, because
     * the environment is allowed to carry either.
     */
    private static function asHash(string $value): string
    {
        foreach (['$2y$', '$2a$', '$2b$', '$argon2i$', '$argon2id$'] as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return $value;
            }
        }

        return password_hash($value, PASSWORD_DEFAULT);
    }

    private function adoptMemory(string $id): void
    {
        $legacy = $this->config->memoryPath;
        $result = $this->directories->adoptLegacyCorpus($id, $legacy);
        if ([] === $result['moved'] && [] === $result['skipped']) {
            return;
        }

        $this->logger->info('users.migration.memory', [
            'id' => $id,
            'from' => $legacy,
            'to' => $this->paths->memory($id),
            'moved' => $result['moved'],
            'skipped' => $result['skipped'],
        ]);
    }
}
