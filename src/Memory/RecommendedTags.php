<?php

declare(strict_types=1);

namespace MemoryDown\Memory;

/**
 * Recommended, entirely optional tags suggested to users (admin UI) and to AI
 * agents (MCP instructions / tool descriptions).
 *
 * These are conventions, not a schema: tags remain freeform and nothing is
 * enforced. Keeping the list in one place stops the UI chips and the MCP text
 * from drifting apart.
 */
final class RecommendedTags
{
    /** @var list<string> */
    public const LIST = [
        'task',
        'idea',
        'reference',
        'followup',
    ];

    /**
     * Human-readable descriptions, keyed by tag, for the MCP instructions.
     *
     * @var array<string, string>
     */
    public const DESCRIPTIONS = [
        'task' => 'an actionable item or thing to do',
        'idea' => 'a thought, proposal or possibility',
        'reference' => 'a durable pointer (link, system, doc, config)',
        'followup' => 'something to revisit or check back on',
    ];

    /**
     * One-line summary used in the MCP server instructions and tool descriptions.
     */
    public static function summary(): string
    {
        $parts = [];
        foreach (self::LIST as $tag) {
            $parts[] = '`' . $tag . '` (' . (self::DESCRIPTIONS[$tag] ?? '') . ')';
        }

        return 'Recommended optional tags: ' . implode(', ', $parts)
            . '. Use them only where they fit; do not force them, and do not treat them as a status.';
    }
}
