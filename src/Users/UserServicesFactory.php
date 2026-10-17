<?php

declare(strict_types=1);

namespace MemoryDown\Users;

use MemoryDown\Memory\MemoryIndex;
use MemoryDown\Memory\MemoryStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builds (and per request caches) the memory services of one user.
 *
 * Constructing a store provisions the user's directory tree on first use, which
 * is how a brand new account gets its categories without anyone clicking
 * "create": the first request that resolves it wins, and every later request
 * finds it already there. Nothing is provisioned at boot, so an instance with
 * twenty accounts does no twenty-times-the-work on every request.
 *
 * The cache is per process — that is, per request on PHP-FPM and shared
 * hosting — because both objects hold open state (a directory listing in
 * flight, an SQLite handle) that must not leak into the next user's request.
 */
final class UserServicesFactory
{
    /** @var array<string, UserServices> */
    private array $cache = [];

    public function __construct(
        private readonly UserPaths $paths,
        private readonly bool $indexEnabled = true,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function for(string $userId): UserServices
    {
        if (!UserStore::isValidId($userId)) {
            throw new \InvalidArgumentException('Invalid user id: ' . $userId);
        }

        return $this->cache[$userId] ??= $this->build($userId);
    }

    private function build(string $userId): UserServices
    {
        $memoryPath = $this->paths->memory($userId);
        $indexPath = $this->paths->indexPath($userId);

        // The index lives in the user's own directory: it is a cache, it is
        // disposable, and keeping it beside the corpus it describes means
        // deleting a user cannot leave a stale index of their memory behind.
        $indexDir = dirname($indexPath);
        if (!is_dir($indexDir) && !mkdir($indexDir, 0775, true) && !is_dir($indexDir)) {
            throw new \RuntimeException("Cannot create index directory: {$indexDir}");
        }

        $memory = new MemoryStore($memoryPath, $this->logger);
        $search = new MemoryIndex($memory, $indexPath, $this->logger, $this->indexEnabled);

        return new UserServices($userId, $memory, $search);
    }
}
