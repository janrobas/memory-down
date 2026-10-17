<?php

declare(strict_types=1);

namespace MemoryDown\Users;

use MemoryDown\Memory\MemoryStore;
use MemoryDown\Memory\SearchEngine;

/**
 * The memory services that belong to exactly one user.
 *
 * Everything reachable through MCP or the admin UI is built from one of these,
 * and each is rooted in that user's own directory. Handing the wrong bundle to
 * a request is therefore the only way to cross the user boundary — there is no
 * longer a process-wide MemoryStore that a caller could fall back on by
 * accident.
 */
final class UserServices
{
    public function __construct(
        public readonly string $userId,
        public readonly MemoryStore $memory,
        public readonly SearchEngine $search,
    ) {
    }
}
