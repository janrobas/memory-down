<?php

declare(strict_types=1);

namespace MemoryDown\Users;

use MemoryDown\Memory\PathValidator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The filesystem side of a user's life: taking over a legacy corpus, and
 * putting a deleted user's directory somewhere it can be found again.
 *
 * Kept apart from UserStore (which owns the record) and from the admin UI
 * (which owns neither) so that "delete a user" is one call that cannot be done
 * half-way by accident: the record is only removed once the directory has been
 * safely moved aside.
 */
final class UserDirectories
{
    public function __construct(
        private readonly UserPaths $paths,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Move a user's whole directory into data/users/.trash/.
     *
     * Deletion is explicit but not final, exactly like forgetting a memory: the
     * corpus, the index and the trash of that account are still on disk, under
     * a name that records who it was and when it went, until the user deletes
     * it for good over FTP. Returns the new location, or null when the user
     * never had a directory (an account that was created but never used).
     *
     * Throws when the directory exists but cannot be moved — the caller must
     * then keep the account record, because losing the pointer to a corpus that
     * is still on disk is worse than refusing the delete.
     */
    public function archive(string $id): ?string
    {
        $source = $this->paths->userRoot($id);
        if (!is_dir($source)) {
            return null;
        }

        $trash = $this->paths->trashRoot();
        if (!is_dir($trash) && !mkdir($trash, 0775, true) && !is_dir($trash)) {
            throw new \RuntimeException('Cannot create the deleted-users directory.');
        }

        $target = $this->paths->trashTarget($id);
        for ($n = 2; file_exists($target) && $n <= 999; ++$n) {
            $target = $this->paths->trashTarget($id) . '-' . $n;
        }

        if (!@rename($source, $target)) {
            // A rename can fail across mount points or when a file inside is
            // locked; fall back to a copy that only reports success once every
            // byte is across, then remove the original.
            if (!$this->copyTree($source, $target)) {
                throw new \RuntimeException('Could not move the user directory to ' . $target . '. Nothing was deleted.');
            }
            $this->removeTree($source);
        }

        $this->logger->info('users.archived', ['id' => $id, 'path' => $target]);

        return $target;
    }

    /**
     * Put an archived directory back where it came from.
     *
     * Only used to undo a delete that failed after the move, so an account is
     * never left alive with its memory gone. Returns false when the move back
     * did not happen; the caller logs that.
     */
    public function restore(string $archivePath, string $id): bool
    {
        $target = $this->paths->userRoot($id);
        if (!is_dir($archivePath) || file_exists($target)) {
            return false;
        }
        if (@rename($archivePath, $target)) {
            $this->logger->warning('users.archive_restored', ['id' => $id, 'path' => $archivePath]);

            return true;
        }
        if ($this->copyTree($archivePath, $target)) {
            $this->removeTree($archivePath);
            $this->logger->warning('users.archive_restored', ['id' => $id, 'path' => $archivePath]);

            return true;
        }

        return false;
    }

    /**
     * Bring the memory of a legacy single-user instance under a user's root.
     *
     * Only the corpus moves: directories named like a category, plus the trash.
     * Anything else sitting in the old memory root — the explanatory README.md,
     * a stray file — is left exactly where it is, because it is not memory and
     * moving it would silently invent a layout the store never reads.
     *
     * Never overwrites: a category that already exists for this user is
     * reported as skipped and left alone, so a half-finished migration can be
     * re-run safely.
     *
     * @return array{moved: list<string>, skipped: list<string>}
     */
    public function adoptLegacyCorpus(string $id, string $legacyRoot): array
    {
        $legacyRoot = rtrim($legacyRoot, '/\\');
        $moved = [];
        $skipped = [];
        if (!is_dir($legacyRoot)) {
            return ['moved' => $moved, 'skipped' => $skipped];
        }

        $target = $this->paths->memory($id);
        $entries = @scandir($legacyRoot);
        if (!is_array($entries)) {
            return ['moved' => $moved, 'skipped' => $skipped];
        }

        foreach ($entries as $name) {
            if ('.' === $name || '..' === $name) {
                continue;
            }
            $from = $legacyRoot . DIRECTORY_SEPARATOR . $name;
            if (!is_dir($from)) {
                continue;
            }
            if (!PathValidator::isCategory($name) && PathValidator::TRASH_DIR !== $name) {
                continue;
            }

            $to = $target . DIRECTORY_SEPARATOR . $name;
            if (file_exists($to)) {
                $skipped[] = $name;
                continue;
            }
            if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
                $skipped[] = $name;
                continue;
            }
            if (@rename($from, $to)) {
                $moved[] = $name;
                continue;
            }
            if ($this->copyTree($from, $to)) {
                $this->removeTree($from);
                $moved[] = $name;
                continue;
            }
            // A failed copy must not leave a half-written category behind: on a
            // later run it would look like a category that is already there and
            // the remaining files would never be brought across.
            $this->removeTree($to);
            $skipped[] = $name;
        }

        return ['moved' => $moved, 'skipped' => $skipped];
    }

    private function copyTree(string $from, string $to): bool
    {
        if (!is_dir($from)) {
            return false;
        }
        if (!is_dir($to) && !mkdir($to, 0775, true) && !is_dir($to)) {
            return false;
        }

        $ok = true;
        foreach (scandir($from) ?: [] as $name) {
            if ('.' === $name || '..' === $name) {
                continue;
            }
            $source = $from . DIRECTORY_SEPARATOR . $name;
            $dest = $to . DIRECTORY_SEPARATOR . $name;
            if (is_dir($source)) {
                $ok = $this->copyTree($source, $dest) && $ok;
                continue;
            }
            $ok = @copy($source, $dest) && $ok;
        }

        return $ok;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ('.' === $name || '..' === $name) {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $name;
            if (is_dir($child)) {
                $this->removeTree($child);
                continue;
            }
            @unlink($child);
        }
        @rmdir($path);
    }
}
