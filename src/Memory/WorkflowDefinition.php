<?php

declare(strict_types=1);

namespace MemoryDown\Memory;

/**
 * The shape of a workflow definition, in one place.
 *
 * docs/WORKFLOWS.md is the normative standard (in Slovenian); this class is the
 * same standard in code, so the template the admin UI pre-fills, the advisory
 * verdict the UI shows and the tests cannot drift apart.
 *
 * Three properties are load-bearing:
 *
 * - The configuration lives in the BODY, in the first fenced `yaml` block.
 *   MemoryStore rebuilds the frontmatter from a fixed key list on every save, so
 *   a `schedule:` written there would be deleted by the next autosave.
 * - A definition is an entry in the `workflows` category carrying the reserved
 *   tag `executable`, which only the admin UI may add (PathValidator).
 * - Nothing here executes or authorises anything. MemoryDown stores definitions
 *   and reports what a runner would refuse; the runner stays the authority.
 */
final class WorkflowDefinition
{
    /** The only category a definition may live in. */
    public const CATEGORY = 'workflows';

    /**
     * The reserved tag that turns a note into a definition.
     *
     * Kept in step with {@see PathValidator::HUMAN_ONLY_TAGS} by a unit check;
     * that list is what stops an agent from granting itself the tag.
     */
    public const TAG = 'executable';

    public const DEFAULT_TIMEZONE = 'Europe/Ljubljana';

    public const MODE_DRY_RUN = 'dry-run';

    public const MODE_APPLY = 'apply';

    /** @var list<string> */
    public const MODES = [self::MODE_DRY_RUN, self::MODE_APPLY];

    /**
     * Fields the configuration block may carry. Anything else is reported as
     * unknown (a runner ignores it and says so) — an unknown field never widens
     * what a definition may do.
     *
     * @var list<string>
     */
    public const FIELDS = [
        'mode',
        'schedule',
        'timezone',
        'allowed_tools',
        'llm_provider',
        'llm_model',
    ];

    /**
     * Markers that make a definition a draft, which a runner never executes.
     * Searched for case-sensitively in the whole body, so the markers below are
     * the documented spelling.
     *
     * @var list<string>
     */
    public const DRAFT_MARKERS = ['TODO', 'FIXME', '<placeholder>', '…'];

    /**
     * The canonical body of a new definition: what the admin UI pre-fills when a
     * new memory is created in the `workflows` category.
     *
     * This is the whole structure of one executable workflow — a configuration
     * block followed by instructions in natural language — and it is deliberately
     * NOT runnable: the instructions still carry a TODO and the model is still a
     * placeholder, so a definition that was activated before it was filled in is
     * refused instead of executed.
     *
     * The title is not part of the body: MemoryStore writes "# Title" itself.
     * The `schedule` line is commented out, so a new definition starts as
     * manual-only; uncommenting it is an explicit act.
     */
    public const TEMPLATE_BODY = <<<'MD'
```yaml
# Kdaj naj se zažene: 5-poljski cron (minuta ura dan-v-mesecu dan-v-tednu), v
# časovnem pasu spodaj. Brez te vrstice se zažene samo ročno.
# schedule: "0 7 * * *"
timezone: Europe/Ljubljana

# Kako se obnaša: dry-run samo bere in poroča, kaj bi naredil. apply sme
# spreminjati, vsako mutirajoče dejanje pa potrdiš ti ob klicu.
mode: dry-run

# Katera orodja sme klicati. Kar ni na seznamu, izvajalec zavrne. Brez seznama
# sme le brati.
allowed_tools:
  - memory.search_memory
  - memory.read_memory

# S katerim modelom naj teče. Vpiši pravi id modela.
llm_provider: opencode-go
llm_model: <model-id>
```

## Namen

TODO: kaj naj workflow naredi in zakaj.

## Navodila

1. TODO: koraki, ki naj jih izvajalec izvede.
2. Če je karkoli nejasno ali manjka podatek, pripravi predlog in vprašanje namesto ugibanja.

## Poročilo

Na koncu vedno pošlji kratek povzetek: kaj je bilo narejeno, kaj je negotovo in kaj potrebuje mojo odločitev.
MD;

    /** True when the category is the one definitions live in. */
    public static function isWorkflowCategory(string $category): bool
    {
        return self::CATEGORY === strtolower(trim($category));
    }

    /**
     * True when a tag list carries the reserved tag.
     *
     * Accepts whatever a caller has (a parsed document, a tool argument, a raw
     * request value); only scalars can spell a tag.
     *
     * @param mixed $tags
     */
    public static function hasTag(mixed $tags): bool
    {
        if (!is_array($tags)) {
            return false;
        }
        foreach ($tags as $tag) {
            if (is_scalar($tag) && self::TAG === strtolower(trim((string) $tag))) {
                return true;
            }
        }

        return false;
    }

    /** True when this entry is an activated definition: the tag, in `workflows`. */
    public static function isDefinition(string $category, mixed $tags): bool
    {
        return self::isWorkflowCategory($category) && self::hasTag($tags);
    }

    /**
     * What a runner would refuse this entry for, or null when the entry is not an
     * activated definition (a plain note, or another category) and the verdict
     * would be meaningless.
     *
     * @param mixed $tags
     *
     * @return list<string>|null
     */
    public static function activationProblems(string $category, mixed $tags, string $body): ?array
    {
        if (!self::isDefinition($category, $tags)) {
            return null;
        }

        return self::parse($body)['problems'];
    }

    /**
     * Read a definition body: its configuration, its instructions, and everything
     * a runner would refuse (docs/WORKFLOWS.md §8).
     *
     * Advisory by design. A half-written draft must stay savable — activation is
     * what makes a definition operative, not a validated save — and the runner,
     * not this parser, decides. Nothing here throws.
     *
     * @return array{config: array<string, mixed>, instructions: string, problems: list<string>}
     */
    public static function parse(string $body): array
    {
        if (1 !== preg_match('/^```yaml[ \t]*\R(.*?)^```[ \t]*(?:\R|$)/ms', $body, $m)) {
            return [
                'config' => [],
                'instructions' => trim($body),
                'problems' => ['No fenced yaml configuration block in the body (the first block must start with ```yaml).'],
            ];
        }

        $block = $m[0];
        $raw = $m[1];
        $offset = (int) strpos($body, $block);
        $before = substr($body, 0, $offset);
        $instructions = substr($body, $offset + strlen($block));

        $problems = [];

        // A leading H1 is the title, which the store strips on read; any other
        // text before the block is not part of the instructions and would be
        // silently lost, so it is worth saying out loud.
        $stray = preg_replace('/^(?:\s*#\s+.*(?:\R|$))*/', '', $before) ?? $before;
        if ('' !== trim($stray)) {
            $problems[] = 'Anything before the configuration block is not part of the instructions; the block must come first.';
        }

        ['config' => $config, 'problems' => $parsed] = self::parseConfigBlock($raw);
        $problems = array_merge($problems, $parsed);

        foreach (array_keys($config) as $key) {
            if (!in_array($key, self::FIELDS, true)) {
                $problems[] = 'Unknown field "' . $key . '" (a runner ignores it and reports it; it never widens what a definition may do).';
            }
        }

        $mode = $config['mode'] ?? self::MODE_DRY_RUN;
        if (!is_string($mode) || !in_array($mode, self::MODES, true)) {
            $problems[] = '"mode" must be ' . implode(' or ', self::MODES) . '; found ' . self::describe($mode) . '.';
        }

        $schedule = $config['schedule'] ?? null;
        if (is_string($schedule) && '' !== trim($schedule) && !self::isCron($schedule)) {
            $problems[] = '"schedule" must be a 5-field cron expression (or absent for a manual-only workflow); found ' . self::describe($schedule) . '.';
        }

        $timezone = $config['timezone'] ?? null;
        if (is_string($timezone) && '' !== trim($timezone) && !self::isTimezone($timezone)) {
            $problems[] = '"timezone" must be an IANA zone name such as ' . self::DEFAULT_TIMEZONE . '; found ' . self::describe($timezone) . '.';
        }

        $tools = $config['allowed_tools'] ?? null;
        if (is_array($tools)) {
            foreach ($tools as $tool) {
                if (!is_string($tool) || '' === trim($tool)) {
                    $problems[] = '"allowed_tools" must be a list of tool ids (one per "- " line).';
                    break;
                }
            }
        } elseif (null !== $tools) {
            $problems[] = '"allowed_tools" must be a list of tool ids (one per "- " line).';
        }

        if ('' === trim($instructions)) {
            $problems[] = 'The instructions after the configuration block are empty.';
        }

        // Draft markers are searched in the whole body, configuration included: a
        // `<model-id>` left in the block is exactly as much of a draft as a TODO
        // left in the instructions.
        foreach (self::draftMarkers($body) as $marker) {
            $problems[] = 'Draft marker "' . $marker . '": a runner refuses to execute a definition that still contains one.';
        }

        return [
            'config' => $config,
            'instructions' => trim($instructions),
            'problems' => $problems,
        ];
    }

    /**
     * Parse the flat "key: value" configuration block: scalars, one level of
     * "- " list items, "#" comments. Nested mappings are deliberately not part of
     * the format (docs/WORKFLOWS.md §4 keeps the schema flat).
     *
     * @return array{config: array<string, mixed>, problems: list<string>}
     */
    private static function parseConfigBlock(string $raw): array
    {
        $config = [];
        $problems = [];
        $listKey = null;

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = ltrim(rtrim($line));
            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }

            if (1 === preg_match('/^([A-Za-z_][A-Za-z0-9_]*):[ \t]*(.*)$/', $line, $pair)) {
                $listKey = null;
                $value = trim($pair[2]);
                if ('' === $value) {
                    // An empty value starts a list; a genuinely empty scalar is
                    // not part of the format.
                    $config[$pair[1]] = [];
                    $listKey = $pair[1];
                    continue;
                }
                $config[$pair[1]] = self::scalar($value);
                continue;
            }

            if (1 === preg_match('/^-[ \t]*(.*)$/', $line, $item)) {
                if (null === $listKey) {
                    $problems[] = 'A "- " item has no field name above it (' . $line . ').';
                    continue;
                }
                if (!is_array($config[$listKey])) {
                    $config[$listKey] = [];
                }
                $config[$listKey][] = self::scalar(trim($item[1]));
                continue;
            }

            $problems[] = 'Not a "key: value" line in the configuration block: ' . $line;
        }

        return ['config' => $config, 'problems' => $problems];
    }

    /**
     * A flat YAML scalar: quotes are stripped, "true"/"false"/"null" become their
     * PHP values, and an unquoted trailing " # comment" is dropped.
     */
    private static function scalar(string $value): string|bool|null
    {
        if ('' !== $value && ('"' === $value[0] || "'" === $value[0]) && strlen($value) > 1) {
            $quote = $value[0];
            if (str_ends_with($value, $quote)) {
                return substr($value, 1, -1);
            }
        }

        $value = trim((string) preg_replace('/\s+#.*$/', '', $value));

        if (1 === preg_match('/^(true|false)$/i', $value)) {
            return 'true' === strtolower($value);
        }
        if (1 === preg_match('/^null$/i', $value)) {
            return null;
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function draftMarkers(string $body): array
    {
        $found = [];
        foreach (self::DRAFT_MARKERS as $marker) {
            if (str_contains($body, $marker)) {
                $found[] = $marker;
            }
        }
        // Any angle-bracket placeholder (<model-id>, <your-name>, …) is a draft
        // marker too, not just the literal in the list.
        if (1 === preg_match('/<[a-z][a-z0-9_-]*>/i', $body)) {
            $found[] = '<placeholder>';
        }

        return array_values(array_unique($found));
    }

    /**
     * A 5-field cron expression ("minute hour day month weekday"), as used by the
     * standard: numbers, the usual three-letter names, lists, ranges and steps.
     */
    private static function isCron(string $expression): bool
    {
        $fields = preg_split('/\s+/', trim($expression)) ?: [];
        if (5 !== count($fields)) {
            return false;
        }

        $bounds = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];
        foreach (array_values($fields) as $index => $field) {
            [$min, $max] = $bounds[$index];
            if (!self::isCronField($field, $min, $max)) {
                return false;
            }
        }

        return true;
    }

    private static function isCronField(string $field, int $min, int $max): bool
    {
        if ('' === $field) {
            return false;
        }
        foreach (explode(',', $field) as $part) {
            if (1 === preg_match('/^(.+)\/(\d+)$/', $part, $step)) {
                $size = (int) $step[2];
                if ($size < 1 || $size > $max) {
                    return false;
                }
                if ('*' === $step[1]) {
                    continue;
                }
                $part = $step[1];
            }
            if ('*' === $part) {
                continue;
            }
            if (1 === preg_match('/^[a-z]{3}$/i', $part)) {
                continue; // jan…dec, sun…sat
            }
            if (1 === preg_match('/^[a-z]{3}-[a-z]{3}$/i', $part)) {
                continue; // mon-fri
            }
            if (1 === preg_match('/^(\d+)-(\d+)$/', $part, $range)) {
                if ((int) $range[1] < $min || (int) $range[2] > $max || (int) $range[1] > (int) $range[2]) {
                    return false;
                }
                continue;
            }
            if (1 !== preg_match('/^\d+$/', $part) || (int) $part < $min || (int) $part > $max) {
                return false;
            }
        }

        return true;
    }

    private static function isTimezone(string $zone): bool
    {
        return in_array($zone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true);
    }

    /**
     * A short, readable rendering of a value for a problem message.
     */
    private static function describe(mixed $value): string
    {
        if (is_array($value)) {
            return 'a list';
        }
        if (null === $value) {
            return 'nothing';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return '"' . (string) $value . '"';
    }
}
