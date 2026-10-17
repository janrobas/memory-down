<?php

declare(strict_types=1);

namespace MemoryDown\Users;

/**
 * Where one user's data lives: data/users/<id>/.
 *
 * The layout keeps everything a person owns under a single directory, so it can
 * be copied, archived or moved by hand over FTP without consulting the
 * application:
 *
 *   data/users/alice/memory/              the Markdown corpus (source of truth)
 *   data/users/alice/memory/.trash/       her deleted entries, restorable
 *   data/users/alice/index/memory.sqlite  her disposable search index
 *
 * Nothing here is shared between users, which is the whole point: no query, no
 * search and no file operation can reach across the boundary, because there is
 * no path from one user's root to another's.
 *
 * Deleted users are moved aside to data/users/.trash/ instead of being
 * unlinked, the same recoverable-delete rule the memory store follows. The
 * leading dot is load-bearing: no valid user id can start with one, so the
 * trash directory can never be mistaken for a user.
 */
final class UserPaths
{
    public const TRASH_DIR = '.trash';

    private string $root;

    /**
     * @param string $usersRoot the directory that holds the user directories
     *                          (DATA_PATH/users), not the data directory itself
     */
    public function __construct(string $usersRoot)
    {
        $this->root = rtrim($usersRoot, '/\\');
    }

    public function root(): string
    {
        return $this->root;
    }

    public function userRoot(string $id): string
    {
        return $this->root . DIRECTORY_SEPARATOR . $this->safeId($id);
    }

    public function memory(string $id): string
    {
        return $this->userRoot($id) . DIRECTORY_SEPARATOR . 'memory';
    }

    public function indexPath(string $id): string
    {
        return $this->userRoot($id) . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . 'memory.sqlite';
    }

    /**
     * Where one user's MCP transport sessions are kept. Scoped per user so a
     * session id (an RFC 4122 UUID the client sends back) cannot be replayed
     * against another account: the session simply is not in that user's
     * directory.
     */
    public function mcpSessions(string $root, string $id): string
    {
        return rtrim($root, '/\\') . DIRECTORY_SEPARATOR . $this->safeId($id);
    }

    public function trashRoot(): string
    {
        return $this->root . DIRECTORY_SEPARATOR . self::TRASH_DIR;
    }

    /**
     * Where a deleted user's directory is parked: "<stamp>__<id>", mirroring
     * the memory trash naming so a name is never ambiguous.
     */
    public function trashTarget(string $id, ?int $now = null): string
    {
        $stamp = gmdate('Ymd\THis\Z', $now ?? time());

        return $this->trashRoot() . DIRECTORY_SEPARATOR . $stamp . '__' . $this->safeId($id);
    }

    /**
     * The id has already been validated by UserStore before it gets here; this
     * is the second line of defence, because every path built from it becomes a
     * filesystem operation.
     */
    private function safeId(string $id): string
    {
        if (!UserStore::isValidId($id)) {
            throw new \InvalidArgumentException('Invalid user id: ' . $id);
        }

        return $id;
    }
}
