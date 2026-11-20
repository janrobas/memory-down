<?php

declare(strict_types=1);

namespace MemoryDown\Users;

use MemoryDown\Memory\Corpora;
use MemoryDown\Memory\MemoryStore;
use MemoryDown\Memory\SearchEngine;

/**
 * The corpora reachable through one MCP connector.
 *
 * Two shapes, one rule:
 *
 *  - with $restrict = [id] it is the ordinary `/mcp` connector: exactly one
 *    owner, the account the bearer token names. The restriction is what keeps
 *    the single-account endpoint single-account even though the tools now accept
 *    an `owner` argument — the argument cannot name what the connector cannot
 *    see.
 *  - with $restrict = null it is the admin connector (`/mcp/all`): every enabled
 *    account.
 *
 * Disabled accounts are invisible in both shapes. Disabling an account is about
 * credentials, but the decision taken for this connector is stricter than that:
 * a disabled account is not served here at all, so its memory neither appears in
 * a cross-account search nor can be written to. Re-enabling makes it visible
 * again, because nothing was destroyed.
 *
 * Nothing is provisioned here: {@see UserServicesFactory::for()} builds (and
 * provisions) the stores lazily, only for the owners a call actually touches.
 */
final class UserCorpora implements Corpora
{
    /** @var list<string>|null null = every enabled account */
    private readonly ?array $restrict;

    /** @var list<string>|null per-request cache; the user store is a JSON read */
    private ?array $owners = null;

    /**
     * @param list<string>|null $restrict a single visible owner, or null for all
     */
    public function __construct(
        private readonly UserStore $users,
        private readonly UserServicesFactory $factory,
        ?array $restrict = null,
    ) {
        $this->restrict = $restrict;
    }

    public static function only(UserStore $users, UserServicesFactory $factory, string $owner): self
    {
        return new self($users, $factory, [$owner]);
    }

    public function owners(): array
    {
        if (null !== $this->owners) {
            return $this->owners;
        }

        $allowed = null === $this->restrict ? null : array_flip($this->restrict);
        $ids = [];
        foreach ($this->users->all() as $user) {
            if (!$user->enabled) {
                continue;
            }
            if (null !== $allowed && !isset($allowed[$user->id])) {
                continue;
            }
            $ids[] = $user->id;
        }

        return $this->owners = $ids;
    }

    public function has(string $owner): bool
    {
        return in_array($owner, $this->owners(), true);
    }

    public function store(string $owner): MemoryStore
    {
        return $this->services($owner)->memory;
    }

    public function search(string $owner): SearchEngine
    {
        return $this->services($owner)->search;
    }

    private function services(string $owner): UserServices
    {
        if (!$this->has($owner)) {
            // Never build a path from an owner that was not validated: the id
            // would become a directory name, so anything unlisted is refused
            // here rather than handed to the factory.
            throw new \InvalidArgumentException('Unknown or disabled account: ' . $owner);
        }

        return $this->factory->for($owner);
    }
}
