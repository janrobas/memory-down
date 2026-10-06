<?php

declare(strict_types=1);

namespace MemoryDown\Memory;

/**
 * Human-readable descriptions for the built-in categories.
 *
 * Single source of truth shared by the MCP server instructions and the admin
 * UI category suggester, so the two never drift. The order follows
 * {@see PathValidator::DEFAULT_CATEGORIES}; categories without a description
 * fall back to their slug.
 */
final class Categories
{
    /** @var array<string, string> slug => description */
    public const DESCRIPTIONS = [
        'preferences' => 'durable user preferences and settings',
        'projects' => 'ongoing project context and state',
        'decisions' => 'decisions and the reasoning behind them',
        'workflows' => 'recurring processes, procedures and habits the user follows',
        'ideas' => 'thoughts, proposals or possibilities worth keeping',
        'references' => 'durable pointers — links, systems, docs, configs',
        'goals' => 'things the user wants to achieve',
        'facts' => 'general durable facts',
        'people' => 'information about people',
        'context' => 'long-term situational context',
        'notes' => 'catch-all for anything that does not clearly fit the categories above',
    ];

    /**
     * Multilingual keyword hints used ONLY by the on-device category suggester
     * (never shown in the MCP instructions). They give the embedding model more
     * signal for short entries, and cover Slovene alongside English.
     *
     * @var array<string, string>
     */
    public const KEYWORDS = [
        'preferences' => 'preference, likes, prefers, setting, habit; nastavitev, želja, raje, preferenca',
        'projects' => 'project, ongoing work, repo, codebase, milestone; projekt, delo, razvoj, repozitorij',
        'decisions' => 'decision, decided, choice, tradeoff, why; odločitev, odločili, izbira, zakaj',
        'workflows' => 'workflow, process, procedure, routine, steps; postopek, proces, navada, rutina',
        'ideas' => 'idea, thought, proposal, possibility, brainstorm; zamisel, ideja, predlog, možnost',
        'references' => 'reference, link, url, docs, documentation, resource, pointer; referenca, povezava, dokumentacija, vir',
        'goals' => 'goal, objective, target, aim, plan; cilj, namen, mejnik, načrt',
        'facts' => 'fact, information, detail, spec, hardware; dejstvo, podatek, informacija',
        'people' => 'person, people, contact, colleague, friend; oseba, oseb, kontakt, sodelavec',
        'context' => 'context, situation, background, circumstances; kontekst, situacija, okoliščine',
        'notes' => 'note, misc, reminder, jot, catch-all; zapisek, opomba, beležka',
    ];

    /**
     * The "Categories:" block used in the MCP server instructions.
     */
    public static function instructionsBlock(): string
    {
        $lines = ['Categories:'];
        foreach (self::all() as $entry) {
            $lines[] = '- ' . $entry['slug'] . ': ' . $entry['description'];
        }

        return implode("\n", $lines);
    }

    /**
     * Ordered slug/description/keywords list for the UI (used by the category suggester).
     *
     * @return list<array{slug: string, description: string, keywords: string}>
     */
    public static function all(): array
    {
        $out = [];
        foreach (PathValidator::DEFAULT_CATEGORIES as $slug) {
            $out[] = [
                'slug' => $slug,
                'description' => self::DESCRIPTIONS[$slug] ?? $slug,
                'keywords' => self::KEYWORDS[$slug] ?? '',
            ];
        }

        return $out;
    }
}
