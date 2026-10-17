<?php

declare(strict_types=1);

/**
 * Dependency-free unit tests for the memory core.
 *
 * Covers the pieces where a small regression is easy to miss and expensive:
 * frontmatter parsing (including Windows CRLF line endings), path-traversal
 * guards, and the search-query directive parser. Run with:
 *
 *     php tests/unit-tests.php
 *
 * Exit code 0 = all passed.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use MemoryDown\Config;
use MemoryDown\Auth\TokenStore;
use MemoryDown\Memory\Frontmatter;
use MemoryDown\Memory\MemoryIndex;
use MemoryDown\Memory\MemoryStore;
use MemoryDown\Memory\PathValidator;
use MemoryDown\Memory\RecommendedTags;
use MemoryDown\Memory\SearchQuery;
use MemoryDown\Memory\WorkflowDefinition;
use MemoryDown\Publish\Publications;
use MemoryDown\Support\BearerToken;
use MemoryDown\Support\RateLimiter;
use MemoryDown\Users\Migration;
use MemoryDown\Users\UserDirectories;
use MemoryDown\Users\UserPaths;
use MemoryDown\Users\UserStore;
use MemoryDown\Workflows\WorkflowCatalog;

$passed = 0;
$failed = 0;
$failures = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed, $failures;
    if ($ok) {
        ++$passed;
        echo "  PASS  {$name}\n";
    } else {
        ++$failed;
        $failures[] = $name . ($detail !== '' ? " — {$detail}" : '');
        echo "  FAIL  {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
    }
}

function section(string $title): void
{
    echo "\n== {$title} ==\n";
}

/* ------------------------------------------------------------------ *
 *  Frontmatter
 * ------------------------------------------------------------------ */

section('Frontmatter');

$lf = "---\ntype: facts\ntags:\n  - alpha\n  - beta\ncreated: 2026-01-02\nupdated: 2026-01-03\nsource: chatgpt\nid: example-abc123\n---\n\n# Title\n\nBody text.\n";
$split = Frontmatter::split($lf);
check('LF: frontmatter detected', [] !== $split['frontmatter'], json_encode($split));
check('LF: tags parsed', ['alpha', 'beta'] === ($split['frontmatter']['tags'] ?? null));
check('LF: id parsed', 'example-abc123' === ($split['frontmatter']['id'] ?? null));
check('LF: body excludes frontmatter', trim($split['body']) === "# Title\n\nBody text.", var_export($split['body'], true));

// Regression: a file saved with Windows CRLF endings must still parse.
$crlf = str_replace("\n", "\r\n", $lf);
$splitCrlf = Frontmatter::split($crlf);
check('CRLF: frontmatter detected', [] !== $splitCrlf['frontmatter'], json_encode($splitCrlf));
check('CRLF: tags parsed', ['alpha', 'beta'] === ($splitCrlf['frontmatter']['tags'] ?? null));
check('CRLF: id parsed', 'example-abc123' === ($splitCrlf['frontmatter']['id'] ?? null));
check('CRLF: created parsed', '2026-01-02' === ($splitCrlf['frontmatter']['created'] ?? null));

$plain = Frontmatter::split("# Just a title\n\nNo frontmatter here.\n");
check('no frontmatter: empty map', [] === $plain['frontmatter']);
check('no frontmatter: body intact', str_contains($plain['body'], 'No frontmatter here.'));

$rendered = Frontmatter::render(['type' => 'facts', 'tags' => ['a', 'b'], 'archived' => true]);
$roundTrip = Frontmatter::parse(substr($rendered, 4, -4));
check('render/parse round trip: tags', ['a', 'b'] === ($roundTrip['tags'] ?? null));
check('render/parse round trip: bool', true === ($roundTrip['archived'] ?? null));

// ISO-8601 timestamps contain ':' but must stay valid, unquoted YAML scalars.
$tsRendered = Frontmatter::render(['created' => '2026-08-27T09:15:00Z', 'updated' => '2026-08-27T14:32:05Z']);
check('render: ISO timestamp stays unquoted', str_contains($tsRendered, 'updated: 2026-08-27T14:32:05Z'), $tsRendered);
$tsRoundTrip = Frontmatter::parse(substr($tsRendered, 4, -4));
check('render/parse round trip: ISO timestamp', '2026-08-27T14:32:05Z' === ($tsRoundTrip['updated'] ?? null), json_encode($tsRoundTrip));

/* ------------------------------------------------------------------ *
 *  PathValidator
 * ------------------------------------------------------------------ */

section('PathValidator');

$root = sys_get_temp_dir() . '/memorydown-unit-' . getmypid();
$paths = new PathValidator($root);

check('isId: normal slug', PathValidator::isId('notes-2026-abc123'));
check('isId: rejects traversal', !PathValidator::isId('../secret'));
check('isId: rejects slash', !PathValidator::isId('a/b'));
check('isId: rejects empty', !PathValidator::isId(''));
check('isCategory: normal', PathValidator::isCategory('preferences'));
check('isCategory: workflows', PathValidator::isCategory('workflows'));
check('isCategory: rejects slash', !PathValidator::isCategory('a/b'));
check('isCategory: rejects traversal', !PathValidator::isCategory('..'));
check('default categories include workflows', in_array('workflows', PathValidator::DEFAULT_CATEGORIES, true));
check('default categories include ideas', in_array('ideas', PathValidator::DEFAULT_CATEGORIES, true));
check('default categories include references', in_array('references', PathValidator::DEFAULT_CATEGORIES, true));
check('default categories include goals', in_array('goals', PathValidator::DEFAULT_CATEGORIES, true));
check('default categories include writings', in_array('writings', PathValidator::DEFAULT_CATEGORIES, true));
check('write-protected: writings is read-only for agents', PathValidator::isWriteProtectedCategory('writings'));
check('write-protected: facts is writable', !PathValidator::isWriteProtectedCategory('facts'));
check('write-protected: case/space tolerant', PathValidator::isWriteProtectedCategory(' Writings '));
check('write-protected: empty category is not protected', !PathValidator::isWriteProtectedCategory(''));
check(
    'write-protected: every protected category is a real category',
    [] === array_diff(PathValidator::WRITE_PROTECTED_CATEGORIES, PathValidator::DEFAULT_CATEGORIES),
    json_encode(PathValidator::WRITE_PROTECTED_CATEGORIES)
);
// The publisher and the MCP write guard must agree on which category is which.
check(
    'write-protected: covers the published category',
    in_array(Publications::CATEGORY, PathValidator::WRITE_PROTECTED_CATEGORIES, true),
    Publications::CATEGORY
);

// Reserved, human-only tags: only the admin UI may add them, and an entry that
// carries one is read-only through MCP (docs/WORKFLOWS.md).
check('human-only: executable is reserved', PathValidator::isHumanOnlyTag('executable'));
check('human-only: case/space tolerant', PathValidator::isHumanOnlyTag(' Executable '));
check('human-only: ordinary tags are not reserved', !PathValidator::isHumanOnlyTag('workflow'));
check('human-only: an empty tag is not reserved', !PathValidator::isHumanOnlyTag(''));
check('human-only: detected in a tag list', 'executable' === PathValidator::humanOnlyTag(['workflow', 'executable']));
check('human-only: detected case-insensitively', 'executable' === PathValidator::humanOnlyTag(['EXECUTABLE']));
check('human-only: exact match only', '' === PathValidator::humanOnlyTag(['executables', 'my-executable']));
check('human-only: ordinary tag list yields nothing', '' === PathValidator::humanOnlyTag(['workflow', 'software']));
check('human-only: empty list yields nothing', '' === PathValidator::humanOnlyTag([]));
// Whatever a caller hands over must never be mistaken for a reservation.
check('human-only: a missing tags value yields nothing', '' === PathValidator::humanOnlyTag(null));
check('human-only: a non-array tags value yields nothing', '' === PathValidator::humanOnlyTag('executable'));
check('human-only: a nested value yields nothing', '' === PathValidator::humanOnlyTag([['executable']]));
// The guard must not accidentally reserve a category, nor the other way round.
// Note that isCategory() answers "is this a legal directory name" (a slug
// check), not "is this a built-in category" — "executable" is a perfectly valid
// slug. What must hold is that the reservation and the category set stay
// disjoint, in both directions.
check('human-only: no category is a reserved tag', !PathValidator::isHumanOnlyTag('writings'));
check('human-only: the reserved tag is not a built-in category', !in_array('executable', PathValidator::DEFAULT_CATEGORIES, true));
check(
    'human-only: no built-in category is a reserved tag',
    [] === array_intersect(PathValidator::DEFAULT_CATEGORIES, PathValidator::HUMAN_ONLY_TAGS),
    json_encode(array_values(array_intersect(PathValidator::DEFAULT_CATEGORIES, PathValidator::HUMAN_ONLY_TAGS)))
);
check('human-only: the reserved tag is not a write-protected category', !PathValidator::isWriteProtectedCategory('executable'));

/* ------------------------------------------------------------------ *
 *  Workflow definitions
 * ------------------------------------------------------------------ */

section('Workflow definition');

// The reserved tag and the workflow category are two different things: the tag
// says "a runner may execute this", the category says where that may be said.
check('workflow: the tag is the reserved one', in_array(WorkflowDefinition::TAG, PathValidator::HUMAN_ONLY_TAGS, true));
check('workflow: the tag is not the category', WorkflowDefinition::TAG !== WorkflowDefinition::CATEGORY);
// isCategory() is a slug check, so this asserts the store accepts the directory
// name the standard names — not that "executable" is a real category.
check('workflow: the category is a legal category name', PathValidator::isCategory(WorkflowDefinition::CATEGORY));
check('workflow: the category is not write-protected', !PathValidator::isWriteProtectedCategory(WorkflowDefinition::CATEGORY));
check('workflow: isWorkflowCategory accepts the category', WorkflowDefinition::isWorkflowCategory('workflows'));
check('workflow: isWorkflowCategory is case/space tolerant', WorkflowDefinition::isWorkflowCategory(' Workflows '));
check('workflow: isWorkflowCategory rejects other categories', !WorkflowDefinition::isWorkflowCategory('notes'));
check('workflow: the tag is found in a tag list', WorkflowDefinition::hasTag(['note', 'executable']));
check('workflow: the tag is case/space tolerant', WorkflowDefinition::hasTag([' EXECUTABLE ']));
check('workflow: an ordinary tag list carries no tag', !WorkflowDefinition::hasTag(['note', 'workflow']));
check('workflow: a missing tags value carries no tag', !WorkflowDefinition::hasTag(null));
check('workflow: a non-list tags value carries no tag', !WorkflowDefinition::hasTag('executable'));
check(
    'workflow: a definition needs both the tag and the category',
    WorkflowDefinition::isDefinition('workflows', ['executable'])
    && !WorkflowDefinition::isDefinition('notes', ['executable'])
    && !WorkflowDefinition::isDefinition('workflows', ['workflow'])
);
check('workflow: a plain note has no verdict', null === WorkflowDefinition::activationProblems('workflows', [], 'Samo zapisek.'));
check('workflow: a tagged definition has a verdict', is_array(WorkflowDefinition::activationProblems('workflows', ['executable'], 'x')));

// The template the admin UI pre-fills for a new workflow.
$template = WorkflowDefinition::TEMPLATE_BODY;
$parsed = WorkflowDefinition::parse($template);
$problems = implode(' | ', $parsed['problems']);

check('template: carries no frontmatter of its own', !str_starts_with(ltrim($template), '---'));
check('template: carries no H1 (the title is a field)', !str_starts_with(ltrim($template), '#'));
check('template: opens with the yaml block', str_starts_with($template, "```yaml\n"));
check('template: no unknown fields', [] === array_diff(array_keys($parsed['config']), WorkflowDefinition::FIELDS), json_encode(array_keys($parsed['config'])));
check('template: mode is dry-run', WorkflowDefinition::MODE_DRY_RUN === ($parsed['config']['mode'] ?? null), json_encode($parsed['config']));
check('template: no schedule, so it starts manual-only', !array_key_exists('schedule', $parsed['config']), json_encode($parsed['config']));
check('template: timezone is the documented default', WorkflowDefinition::DEFAULT_TIMEZONE === ($parsed['config']['timezone'] ?? null));
check('template: allowed_tools is a list of ids', ['memory.search_memory', 'memory.read_memory'] === ($parsed['config']['allowed_tools'] ?? null));
check('template: names a provider and a model', isset($parsed['config']['llm_provider'], $parsed['config']['llm_model']));
check('template: instructions survive parsing', str_contains($parsed['instructions'], '## Navodila'));
// Load-bearing: the template is a draft, so activating it before filling it in
// cannot produce something a runner would execute.
check('template: is refused while it is a draft', [] === array_filter($parsed['problems'], static fn (string $p): bool => !str_contains($p, 'Draft marker')), $problems);
check('template: the refusal names the TODO', str_contains($problems, 'TODO'), $problems);
check('template: the refusal names the placeholder model', str_contains($problems, '<placeholder>'), $problems);

// A definition a user actually filled in.
$filled = "```yaml\n"
    . "mode: apply\n"
    . "schedule: \"30 6 * * mon-fri\"\n"
    . "timezone: Europe/Ljubljana\n"
    . "allowed_tools:\n"
    . "  - calendar.get_events\n"
    . "  - memory.search_memory\n"
    . "llm_provider: opencode-go\n"
    . "llm_model: qwen3\n"
    . "```\n\n"
    . "Preglej koledar in porocaj.\n";
$ok = WorkflowDefinition::parse($filled);
check('definition: a filled-in definition has no problems', [] === $ok['problems'], implode(' | ', $ok['problems']));
check('definition: values are parsed', 'apply' === ($ok['config']['mode'] ?? null) && 'qwen3' === ($ok['config']['llm_model'] ?? null), json_encode($ok['config']));
check('definition: instructions are the text after the block', 'Preglej koledar in porocaj.' === $ok['instructions'], var_export($ok['instructions'], true));

// Each rule a runner applies, and that this parser reports.
$bad = static fn (string $body): string => implode(' | ', WorkflowDefinition::parse($body)['problems']);
check(
    'definition: a missing block is reported',
    str_contains($bad('Samo navodila brez bloka.'), 'configuration block'),
    $bad('Samo navodila brez bloka.')
);
check(
    'definition: text before the block is reported',
    str_contains($bad("Uvodni odstavek.\n\n```yaml\nmode: dry-run\n```\n\nNavodila.\n"), 'before the configuration block')
);
check(
    'definition: an unknown mode is reported',
    str_contains($bad("```yaml\nmode: sometimes\n```\n\nNavodila.\n"), 'mode')
);
check(
    'definition: a four-field cron is reported',
    str_contains($bad("```yaml\nschedule: \"0 7 * *\"\n```\n\nNavodila.\n"), 'schedule')
);
check(
    'definition: an out-of-range cron is reported',
    str_contains($bad("```yaml\nschedule: \"99 7 * * *\"\n```\n\nNavodila.\n"), 'schedule')
);
check(
    'definition: a bad timezone is reported',
    str_contains($bad("```yaml\ntimezone: Mars/Olympus\n```\n\nNavodila.\n"), 'timezone')
);
check(
    'definition: an unknown field is reported',
    str_contains($bad("```yaml\nmode: dry-run\nlimits:\n  - 5\n```\n\nNavodila.\n"), 'limits')
);
check(
    'definition: empty instructions are reported',
    str_contains($bad("```yaml\nmode: dry-run\n```\n"), 'instructions')
);
check(
    'definition: a stray list item is reported',
    str_contains($bad("```yaml\n  - sirota\n```\n\nNavodila.\n"), 'field name')
);
check(
    'definition: an angle-bracket placeholder is a draft marker',
    str_contains($bad("```yaml\nmode: dry-run\n```\n\nPoslji na <your-address>.\n"), '<placeholder>')
);
check(
    'definition: a commented line is not a field',
    [] === WorkflowDefinition::parse("```yaml\n# schedule: \"0 7 * * *\"\nmode: dry-run\n```\n\nNavodila.\n")['problems'],
    $bad("```yaml\n# schedule: \"0 7 * * *\"\nmode: dry-run\n```\n\nNavodila.\n")
);
check(
    'definition: a quoted value keeps its spaces',
    '0 7 * * *' === (WorkflowDefinition::parse("```yaml\nschedule: \"0 7 * * *\"\n```\n\nNavodila.\n")['config']['schedule'] ?? null)
);
check(
    'definition: an inline comment is dropped from a value',
    'dry-run' === (WorkflowDefinition::parse("```yaml\nmode: dry-run # zacasno\n```\n\nNavodila.\n")['config']['mode'] ?? null)
);
check(
    'definition: a leading H1 before the block is the title, not an error',
    [] === WorkflowDefinition::parse("# Naslov\n\n```yaml\nmode: dry-run\n```\n\nNavodila.\n")['problems']
);

// `mode: yolo` is a known mode now: a runner may execute it without asking, but
// only when its own instance-wide switch is on — which is the runner's business,
// not something MemoryDown reads or stores (docs/WORKFLOWS.md §7).
check('workflow: yolo is a known mode', in_array('yolo', WorkflowDefinition::MODES, true), json_encode(WorkflowDefinition::MODES));
$yolo = WorkflowDefinition::parse("```yaml\nmode: yolo\nschedule: \"* * * * *\"\nallowed_tools:\n  - \"*\"\n```\n\nOdgovori na posto.\n");
check('definition: mode yolo is accepted', [] === $yolo['problems'], implode(' | ', $yolo['problems']));
check('definition: a yolo definition is not refused', [] === WorkflowDefinition::activationProblems('workflows', ['executable'], "```yaml\nmode: yolo\n```\n\nNavodila.\n"));

// The wildcard is a list item and stays as written: expanding it is the runner's
// job at the moment of the call, so the stored definition must not be rewritten.
check('definition: a quoted wildcard is one list item', ['*'] === ($yolo['config']['allowed_tools'] ?? null), json_encode($yolo['config']['allowed_tools'] ?? null));
check('definition: a server wildcard is one list item', ['calendar.*'] === (WorkflowDefinition::parse("```yaml\nmode: dry-run\nallowed_tools:\n  - calendar.*\n```\n\nNavodila.\n")['config']['allowed_tools'] ?? null));
check('definition: a bare wildcard list item parses too (this parser is flat)', ['*'] === (WorkflowDefinition::parse("```yaml\nmode: dry-run\nallowed_tools:\n  - *\n```\n\nNavodila.\n")['config']['allowed_tools'] ?? null));
check('definition: no wildcard is expanded into tool names', ['*'] === ($yolo['config']['allowed_tools'] ?? null) && [] === array_diff(array_keys($yolo['config']), WorkflowDefinition::FIELDS));

// One id needs no list: the scalar form is a one-element allowlist, and a
// genuinely empty value is still a problem.
$scalarTools = WorkflowDefinition::parse("```yaml\nmode: dry-run\nallowed_tools: \"*\"\n```\n\nNavodila.\n");
check('definition: a scalar wildcard is a one-element list', ['*'] === ($scalarTools['config']['allowed_tools'] ?? null), json_encode($scalarTools['config']['allowed_tools'] ?? null));
check('definition: a scalar wildcard raises no problem', [] === $scalarTools['problems'], implode(' | ', $scalarTools['problems']));
check('definition: a scalar tool id is a one-element list', ['calendar.get_events'] === (WorkflowDefinition::parse("```yaml\nmode: dry-run\nallowed_tools: calendar.get_events\n```\n\nNavodila.\n")['config']['allowed_tools'] ?? null));
check(
    'definition: an empty allowed_tools value is still reported',
    str_contains($bad("```yaml\nmode: dry-run\nallowed_tools: \"\"\n```\n\nNavodila.\n"), 'allowed_tools')
);

// The template documents all three modes and the quoting rule for the wildcard.
check('template: the mode comment names yolo', str_contains(WorkflowDefinition::TEMPLATE_BODY, 'yolo'));
check('template: the mode comment names the runner switch', str_contains(WorkflowDefinition::TEMPLATE_BODY, 'AIORCH_ENABLE_YOLO'));
check('template: the tool comment asks for a quoted wildcard', str_contains(WorkflowDefinition::TEMPLATE_BODY, '("*")'));

/* ------------------------------------------------------------------ *
 *  The workflow catalog (read-only API over the workflows category)
 * ------------------------------------------------------------------ */

section('WorkflowCatalog');

$catalogRoot = $root . '/catalog';
$catalogStore = new MemoryStore($catalogRoot . '/memory');
$catalogStore->create(
    "```yaml\nmode: apply\nschedule: \"0 7 * * *\"\ntimezone: Europe/Ljubljana\n```\n\nPreglej posto.\n",
    'Jutranji pregled',
    'workflows',
    ['executable', 'email'],
);
$catalogStore->create('Samo zapisek o postopku.', 'Postopek', 'workflows', ['workflow']);
$catalogStore->create('Tuja kategorija.', 'Ni workflow', 'facts');
$catalog = new WorkflowCatalog($catalogStore);

$list = $catalog->list();
check('catalog: lists only the workflows category', 2 === ($list['total'] ?? -1), json_encode(array_column($list['workflows'] ?? [], 'id')));
check('catalog: envelope matches the writings API', ['count', 'total', 'has_more', 'limit', 'offset'] === array_slice(array_keys($list), 1), json_encode(array_keys($list)));
$definition = null;
$note = null;
foreach ($list['workflows'] as $entry) {
    if (true === $entry['executable']) {
        $definition = $entry;
    } else {
        $note = $entry;
    }
}
check('catalog: the tagged entry reports executable', is_array($definition) && 'Jutranji pregled' === ($definition['title'] ?? ''), json_encode($list['workflows']));
check('catalog: the plain entry reports unexecutable', is_array($note) && 'Postopek' === ($note['title'] ?? ''), json_encode($list['workflows']));
check('catalog: summary carries no body', !array_key_exists('markdown', $definition ?? []), json_encode($definition));
check('catalog: summary leaks no path or source', !array_key_exists('path', $definition ?? []) && !array_key_exists('source', $definition ?? []), json_encode($definition));

$onlyDefinitions = $catalog->list(50, 0, true);
check('catalog: executable=true keeps only the definition', 1 === ($onlyDefinitions['total'] ?? -1) && true === ($onlyDefinitions['workflows'][0]['executable'] ?? null), json_encode($onlyDefinitions));
$onlyNotes = $catalog->list(50, 0, false);
check('catalog: executable=false keeps only the rest', 1 === ($onlyNotes['total'] ?? -1) && false === ($onlyNotes['workflows'][0]['executable'] ?? null), json_encode($onlyNotes));

$paged = $catalog->list(1, 0);
check('catalog: paging returns one entry and has_more', 1 === ($paged['count'] ?? -1) && true === ($paged['has_more'] ?? null), json_encode($paged));
check('catalog: paging past the end is empty', 0 === ($catalog->list(1, 5)['count'] ?? -1) && false === ($catalog->list(1, 5)['has_more'] ?? null), json_encode($catalog->list(1, 5)));

$full = $catalog->get((string) $definition['id']);
check('catalog: get returns the entry', is_array($full) && $definition['id'] === ($full['id'] ?? null), json_encode($full));
check('catalog: get returns the body verbatim', str_starts_with((string) ($full['markdown'] ?? ''), "```yaml\n"), var_export($full['markdown'] ?? null, true));
check('catalog: get returns the parsed config', 'apply' === ($full['config']['mode'] ?? null) && '0 7 * * *' === ($full['config']['schedule'] ?? null), json_encode($full['config'] ?? null));
check('catalog: get returns the instructions', 'Preglej posto.' === trim((string) ($full['instructions'] ?? '')), var_export($full['instructions'] ?? null, true));
check('catalog: get reports authorization', true === ($full['executable'] ?? null), json_encode($full['executable'] ?? null));
check('catalog: get leaks no path or source', !array_key_exists('path', $full ?? []) && !array_key_exists('source', $full ?? []), json_encode($full));

// A note in the same category is readable too: the token is the boundary, the
// tag only says whether a runner may execute it.
$noteFull = $catalog->get((string) $note['id']);
check('catalog: get serves a plain note too', is_array($noteFull) && false === ($noteFull['executable'] ?? null), json_encode($noteFull));
check('catalog: a note without a block has no config', [] === ($noteFull['config'] ?? null), json_encode($noteFull['config'] ?? null));

check('catalog: unknown id is null', null === $catalog->get('does-not-exist'));
check('catalog: traversal id is null', null === $catalog->get('../secret') && null === $catalog->get('..%2F..%2F.env'));
check('catalog: an empty id is null', null === $catalog->get(''));

/* ------------------------------------------------------------------ *
 *  Configuration defaults
 * ------------------------------------------------------------------ */

section('Config');

$minimal = [
    'app_base_url' => 'https://example.test',
    'app_env' => 'production',
    'app_name' => 'MemoryDown',
    'app_version' => '1.0.0',
    'data_path' => '/tmp/data',
    'memory_path' => '/tmp/data/memory',
    'log_path' => '/tmp/data/logs',
    'oauth_access_token_ttl' => 3600,
    'oauth_refresh_token_ttl' => 7776000,
    'oauth_code_ttl' => 600,
    'oauth_username' => '',
    'oauth_consent_password' => '',
    'oauth_cimd_allowed_origins' => [],
    'mcp_session_ttl' => 3600,
];
$config = new Config($minimal);
check('config: the workflows API is off by default', false === $config->workflowsApiEnabled);
check('config: per-account data lives under data/users by default', '/tmp/data/users' === $config->usersPath);
check('config: MCP sessions are per account by default', '/tmp/data/sessions/mcp' === $config->mcpSessionsPath);
check('config: the public writings API is off by default', false === $config->publicApiEnabled);
// The API tokens are per account now (admin UI → Settings), never instance-wide
// environment values: one instance-wide token could not say whose memory to serve.
check('config: exposes no instance-wide API tokens', !property_exists($config, 'publicApiToken') && !property_exists($config, 'workflowsApiToken'));
$enabled = new Config($minimal + ['workflows_api_enabled' => true]);
check('config: an explicit flag is honoured', true === $enabled->workflowsApiEnabled);

// The switch has to be documented where a deployment looks for it; an
// undocumented variable is a variable nobody sets. The token is documented as
// living in the admin UI instead, so its absence from .env.example is the point.
$envExample = (string) @file_get_contents(dirname(__DIR__) . '/.env.example');
check('config: .env.example documents the workflows API switch', str_contains($envExample, 'WORKFLOWS_API_ENABLED=false'), '');
check('config: .env.example no longer carries an instance-wide token', !str_contains($envExample, 'WORKFLOWS_API_TOKEN') && !str_contains($envExample, 'PUBLIC_API_TOKEN'));
// The runner's unattended-run switch is documented in docs/WORKFLOWS.md, not here:
// MemoryDown does not read it and holds no runner configuration, so listing it
// among this application's settings would only suggest it does something.
check('config: .env.example does not claim the runner switch', !str_contains($envExample, 'AIORCH'));

check('recommended tags include question', in_array('question', RecommendedTags::LIST, true));
check('recommended tags include snippet', in_array('snippet', RecommendedTags::LIST, true));
check('recommended tags drop idea', !in_array('idea', RecommendedTags::LIST, true));
check('recommended tags drop reference', !in_array('reference', RecommendedTags::LIST, true));

check('file: valid path stays inside root', null !== $paths->file('facts', 'example-abc123'));
check('file: traversal id rejected', null === $paths->file('facts', '../secret'));
check('file: slash category rejected', null === $paths->file('a/b', 'example-abc123'));
check('categoryDir: traversal rejected', null === $paths->categoryDir('..'));

/* ------------------------------------------------------------------ *
 *  SearchQuery
 * ------------------------------------------------------------------ */

section('SearchQuery');

$q = SearchQuery::parse('tag:project-x hello world');
check('tag directive extracted', ['project-x'] === $q->tags, json_encode($q));
check('free terms kept', ['hello', 'world'] === $q->terms, json_encode($q));

$q = SearchQuery::parse('tag:Alpha tag:beta');
check('multiple tags (AND)', ['alpha', 'beta'] === $q->tags, json_encode($q));
check('tag-only query is not empty', !$q->isEmpty());

$q = SearchQuery::parse('is:archived notes');
check('is:archived flag', true === $q->archived);
$q = SearchQuery::parse('is:active notes');
check('is:active flag', false === $q->archived);

$q = SearchQuery::parse('a bb ccc');
check('single-char terms dropped', ['bb', 'ccc'] === $q->terms, json_encode($q));

$q = SearchQuery::parse('hello')->withExplicit('explicit-tag', true);
check('explicit tag appended', ['explicit-tag'] === $q->tags, json_encode($q));
check('explicit archived wins', true === $q->archived);

check('empty query reported empty', SearchQuery::parse('   ')->isEmpty());

// Quoted multi-word tags stay one tag; quotes are stripped and case folded.
$q = SearchQuery::parse('tag:"ŠC Kranj"');
check('quoted tag: single multi-word tag', ['šc kranj'] === $q->tags, json_encode($q));
check('quoted tag: no stray free-text term', [] === $q->terms, json_encode($q));

$q = SearchQuery::parse("tag:'x y'");
check('single-quoted tag', ['x y'] === $q->tags, json_encode($q));

$q = SearchQuery::parse('tag:"a b" cc');
check('quoted tag plus a free-text term', ['a b'] === $q->tags && ['cc'] === $q->terms, json_encode($q));

$q = SearchQuery::parse('tag:diplome hello');
check('unquoted single tag still works', ['diplome'] === $q->tags && ['hello'] === $q->terms, json_encode($q));

/* ------------------------------------------------------------------ *
 *  MemoryStore: canonical corpus only
 * ------------------------------------------------------------------ */

section('MemoryStore');

$memRoot = $root . '/memory';
$store = new MemoryStore($memRoot);
check('a new memory root explains itself', is_file($memRoot . '/README.md') && str_contains((string) file_get_contents($memRoot . '/README.md'), 'Markdown files are the source of truth'));
$store->create('Canonical body.', 'Canonical', 'facts');

// Files that are not {category}/{id}.md must not be part of the corpus.
@file_put_contents($memRoot . '/README.md', "# Root readme\n");
@mkdir($memRoot . '/facts/nested', 0775, true);
@file_put_contents($memRoot . '/facts/nested/deep.md', "# Deep\n");

check('count only counts canonical entries', 1 === $store->count(), (string) $store->count());
check('collectFiles ignores root + nested .md', 1 === count($store->collectFiles()));
check('collectFiles(category) finds the entry', 1 === count($store->collectFiles('facts')));
check('collectFiles(bogus category) is empty', [] === $store->collectFiles('not-a-real'));

// locate(): the loose references the MCP tools hand out must all resolve, and
// nothing that could name a file outside the corpus may.
check('locate: bare id', ['category' => null, 'id' => 'note-abc123'] === MemoryStore::locate('note-abc123'));
check('locate: id with .md', ['category' => null, 'id' => 'note-abc123'] === MemoryStore::locate('note-abc123.md'));
check('locate: category/id', ['category' => 'facts', 'id' => 'note-abc123'] === MemoryStore::locate('facts/note-abc123'));
check('locate: category/id.md', ['category' => 'writings', 'id' => 'prvi-7b083f'] === MemoryStore::locate('writings/prvi-7b083f.md'));
check('locate: surrounding whitespace ignored', ['category' => null, 'id' => 'note-abc123'] === MemoryStore::locate('  note-abc123  '));
check('locate: uppercase extension accepted', ['category' => null, 'id' => 'note-abc123'] === MemoryStore::locate('note-abc123.MD'));
foreach ([
    'empty' => '',
    'blank' => '   ',
    'parent traversal' => '../secret',
    'deep traversal' => '../../.env',
    'traversal inside a category' => 'facts/../context/x',
    'absolute path' => '/etc/passwd',
    'drive letter' => 'C:/windows/win.ini',
    'backslash separators' => 'facts\\note-abc123',
    'dotfile' => '.env',
    'extra segment' => 'facts/nested/x',
    'trailing separator' => 'facts/',
    'leading separator' => '/facts/x',
    'null byte' => "note-abc123\0.md",
    'bad category' => 'Facts/x',
] as $locateLabel => $badRef) {
    check("locate: rejects {$locateLabel}", null === MemoryStore::locate($badRef), var_export($badRef, true));
}

// raw(): the file exactly as stored, for the frontmatter block. read() parses
// and strips the H1; raw() keeps both.
$rawDoc = $store->create('Raw body.', 'Raw title', 'notes', ['raw']);
$rawText = $store->raw('notes', (string) $rawDoc['id']);
check('raw: returns the stored text', is_string($rawText) && str_starts_with($rawText, "---\n"), var_export($rawText, true));
check('raw: keeps the frontmatter that read() parses away', str_contains((string) $rawText, 'id: ' . $rawDoc['id']), (string) $rawText);
check('raw: keeps the title heading that read() strips', str_contains((string) $rawText, "# Raw title"), (string) $rawText);
check('raw: null for a missing entry', null === $store->raw('notes', 'does-not-exist'));
check('raw: null for an invalid id', null === $store->raw('notes', '../secret'));
check('raw: null for an invalid category', null === $store->raw('a/b', 'x'));

// The title heading and the body are two different things. An entry written
// without a title must not gain a stray "#" heading — that pushed the body down
// and, because the title pattern could run past it, the *next* line became the
// title. A workflow template whose configuration block carries "#" comments is
// exactly where that hurt: the comments were read as the title and the block was
// no longer the first thing in the body.
$untitledBody = "```yaml\n# komentar v bloku\nmode: dry-run\n```\n\nNavodila.\n";
$untitled = $store->create($untitledBody, '', 'workflows');
$untitledRaw = (string) $store->raw('workflows', (string) $untitled['id']);
check('untitled: no stray heading line is written', 0 === preg_match('/^#[ \t]*$/m', $untitledRaw), $untitledRaw);
check('untitled: the title stays empty', '' === $untitled['title'], json_encode($untitled['title']));
check('untitled: the body starts at the configuration block', str_starts_with($untitled['body'], '```yaml'), var_export($untitled['body'], true));
check('untitled: a comment inside the block is not the title', !str_contains($untitled['title'], 'komentar'), (string) $untitled['title']);

// A leading H1 is still adopted when no title was given, and is not duplicated.
$adopted = $store->create("# Naslov iz telesa\n\nTelo.\n", '', 'notes');
check('untitled: a leading H1 is adopted as the title', 'Naslov iz telesa' === $adopted['title'], json_encode($adopted['title']));
check('untitled: the adopted H1 is not left in the body', 'Telo.' === trim($adopted['body']), var_export($adopted['body'], true));

// ... but a line that only looks like one is not: Markdown needs the space, so a
// note that opens with a "#hashtag" keeps it (and keeps no title).
$hashtag = $store->create("#todo\n\nKupi mleko.\n", '', 'notes');
check('untitled: "#todo" is not a heading', '' === $hashtag['title'], json_encode($hashtag['title']));
check('untitled: "#todo" stays in the body', str_starts_with(trim($hashtag['body']), '#todo'), var_export($hashtag['body'], true));

// An entry an older version left with a bare "#" heals: it reads sanely, and the
// next save writes it back without the empty heading.
$legacyId = 'legacy-abc123';
@file_put_contents(
    $memRoot . '/notes/' . $legacyId . '.md',
    "---\ntype: notes\ntags: []\ncreated: 2026-01-01\nupdated: 2026-01-01\nid: {$legacyId}\n---\n\n# \n\nLegacy body.\n"
);
$legacy = $store->read('notes', $legacyId);
check('legacy: a bare heading is not the title', '' === ($legacy['title'] ?? 'x'), json_encode($legacy['title'] ?? null));
check('legacy: a bare heading is stripped from the body', 'Legacy body.' === trim((string) ($legacy['body'] ?? '')), var_export($legacy['body'] ?? null, true));
$store->update('notes', $legacyId, ['title' => '', 'content' => 'Legacy body.']);
check('legacy: the next save drops the bare heading', 0 === preg_match('/^#[ \t]*$/m', (string) $store->raw('notes', $legacyId)), (string) $store->raw('notes', $legacyId));

/* ------------------------------------------------------------------ *
 *  MemoryStore: the trash
 * ------------------------------------------------------------------ */

section('MemoryStore: trash');

$trashRoot = $root . '/trash-store';
$trashStore = new MemoryStore($trashRoot);
$trashPaths = $trashStore->paths();

check('trash: the trash directory is created at boot', is_dir($trashRoot . '/' . PathValidator::TRASH_DIR));
// The leading dot is what keeps the trash out of the corpus: a bare "trash"
// folder WOULD be a valid category.
check('trash: ".trash" is not a category', !PathValidator::isCategory(PathValidator::TRASH_DIR));
check('trash: a bare "trash" WOULD be a category', PathValidator::isCategory('trash'));

$keeper = $trashStore->create('Keep me.', 'Keeper', 'facts');
$doomed = $trashStore->create('Delete me.', 'Doomed', 'facts', ['gone']);
$doomedFile = $trashRoot . '/facts/' . $doomed['id'] . '.md';
check('trash: entry exists before deleting', is_file($doomedFile));

check('trash: delete reports success', $trashStore->delete('facts', (string) $doomed['id']));
check('trash: the file left its category', !is_file($doomedFile));
check('trash: the entry is out of the corpus', 1 === $trashStore->count(), (string) $trashStore->count());
check('trash: the trash directory is not a category', !in_array(PathValidator::TRASH_DIR, $trashStore->categories(), true), json_encode($trashStore->categories()));
check('trash: collectFiles ignores the trash', 1 === count($trashStore->collectFiles()));
check('trash: deleting again is a no-op, not an error', !$trashStore->delete('facts', (string) $doomed['id']));
check('trash: deleting an unknown id is a no-op', !$trashStore->delete('facts', 'never-existed'));

$entries = $trashStore->trashed();
check('trash: the entry is listed', 1 === count($entries), json_encode($entries));
check('trash: original category and id are recoverable from the name',
    'facts' === ($entries[0]['category'] ?? '') && $doomed['id'] === ($entries[0]['id'] ?? ''),
    json_encode($entries[0] ?? null));
check('trash: deleted_at is an ISO-8601 UTC stamp',
    (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) ($entries[0]['deleted_at'] ?? '')),
    (string) ($entries[0]['deleted_at'] ?? ''));
check('trash: size is reported', ($entries[0]['bytes'] ?? 0) > 0, json_encode($entries[0] ?? null));
check('trash: trashCount agrees with trashed()', 1 === $trashStore->trashCount());

// Trash names come from the UI, so they must survive a hostile one.
foreach (['../secret', '../../etc/passwd', '/etc/passwd', 'a/b.md', '', 'notes-abc.md', '20261011T1830Z__FACTS__x.md', "20261011T1830Z__facts__x.md\0"] as $badName) {
    check('trash: rejects a bad entry name ' . var_export($badName, true), null === $trashPaths->trashFile($badName));
}
check('trash: accepts a well-formed entry name', null !== $trashPaths->trashFile((string) $entries[0]['name']));

// Restoring puts it back exactly where it came from.
$where = $trashStore->restore((string) $entries[0]['name']);
check('trash: restore reports the original location', 'facts/' . $doomed['id'] === $where, $where);
check('trash: the file is back', is_file($doomedFile));
check('trash: the corpus has it again', 2 === $trashStore->count(), (string) $trashStore->count());
check('trash: the trash is empty again', 0 === $trashStore->trashCount());
check('trash: the restored entry still reads', 'Delete me.' === rtrim((string) ($trashStore->read('facts', (string) $doomed['id'])['body'] ?? '')), '');

// A restore must never clobber an entry that exists again.
$trashStore->delete('facts', (string) $doomed['id']);
$recreated = $trashStore->create('Newer content.', 'Doomed', 'facts', id: (string) $doomed['id']);
check('trash: an entry with the same id can be recreated', $doomed['id'] === ($recreated['id'] ?? ''), json_encode($recreated['id'] ?? null));
$refused = false;
try {
    $trashStore->restore((string) $trashStore->trashed()[0]['name']);
} catch (\InvalidArgumentException) {
    $refused = true;
}
check('trash: restore refuses to overwrite a live entry', $refused);
check('trash: the live entry was left alone', 'Newer content.' === rtrim((string) ($trashStore->read('facts', (string) $doomed['id'])['body'] ?? '')), '');

// Two deletions of the same path must not overwrite each other.
$trashStore->delete('facts', (string) $doomed['id']);
$twice = $trashStore->trashed();
check('trash: a second deletion is kept alongside the first', 2 === count($twice), json_encode(array_column($twice, 'name')));
check('trash: both copies parse back to the same entry',
    ['facts', 'facts'] === array_column($twice, 'category') && [$doomed['id'], $doomed['id']] === array_column($twice, 'id'),
    json_encode($twice));
check('trash: names are unique', 2 === count(array_unique(array_column($twice, 'name'))));

check('trash: purge removes one entry', $trashStore->purge((string) $twice[0]['name']));
check('trash: purge of a missing entry is false', !$trashStore->purge((string) $twice[0]['name']));
check('trash: one entry is left', 1 === $trashStore->trashCount());
check('trash: emptying removes the rest', 1 === $trashStore->purgeTrash());
check('trash: the trash is empty', 0 === $trashStore->trashCount());
check('trash: emptying an empty trash removes nothing', 0 === $trashStore->purgeTrash());
check('trash: the keeper was never touched', null !== $trashStore->read('facts', (string) $keeper['id']));
// A stray file dropped into the trash folder is ignored, not guessed at.
@file_put_contents($trashRoot . '/' . PathValidator::TRASH_DIR . '/not-a-trash-entry.md', "junk\n");
check('trash: stray files in the folder are ignored', 0 === $trashStore->trashCount());

// trashCount() skips the per-entry work trashed() does, so pin that it is still
// exact: a directory whose name looks like an entry must not be counted.
@mkdir($trashRoot . '/' . PathValidator::TRASH_DIR . '/20261011T1830Z__facts__dir-123456.md', 0775, true);
check('trash: a directory named like an entry is not counted', 0 === $trashStore->trashCount(), (string) $trashStore->trashCount());
check('trash: trashed() ignores it too', [] === $trashStore->trashed());
@rmdir($trashRoot . '/' . PathValidator::TRASH_DIR . '/20261011T1830Z__facts__dir-123456.md');
check('trash: the cheap count matches the full listing', count($trashStore->trashed()) === $trashStore->trashCount());

// B1: created/updated are full ISO-8601 UTC timestamps, and the parsed doc
// exposes a numeric updated_ts used for ordering and the UI "new" badge.
$tsDoc = $store->create('Timestamped body.', 'Timestamped', 'facts');
check('create writes an ISO-8601 updated timestamp',
    (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) ($tsDoc['updated'] ?? '')),
    json_encode($tsDoc['updated'] ?? null));
check('parsed doc exposes a numeric updated_ts', is_int($tsDoc['updated_ts'] ?? null), json_encode($tsDoc['updated_ts'] ?? null));

// A file written by another tool with a date-only (or missing) timestamp still
// yields an updated_ts so ordering never breaks.
@file_put_contents($memRoot . '/facts/legacy-one.md', "---\ntype: facts\nid: legacy-one\ncreated: 2020-01-02\nupdated: 2020-01-03\n---\n\n# Legacy\n\nBody.\n");
$legacyDoc = $store->read('facts', 'legacy-one');
check('legacy date-only updated parses to a timestamp', is_int($legacyDoc['updated_ts'] ?? null), json_encode($legacyDoc['updated_ts'] ?? null));

// The `public` flag mirrors `archived`: absent by default, preserved on update
// unless explicitly changed (so an agent edit cannot unpublish a writing).
$pubDoc = $store->create('Public body.', 'Pub', 'writings', public: true);
check('create stores public flag', true === ($pubDoc['public'] ?? null), json_encode($pubDoc['public'] ?? null));
check('create defaults public to false', false === ($store->create('Plain body.', 'Plain', 'notes')['public'] ?? null));
$keptPublic = $store->update('writings', $pubDoc['id'], ['title' => 'Pub renamed']);
check('update preserves public when not provided', true === ($keptPublic['public'] ?? null), json_encode($keptPublic['public'] ?? null));
$unpub = $store->update('writings', $pubDoc['id'], ['public' => false]);
check('update can clear public', false === ($unpub['public'] ?? null), json_encode($unpub['public'] ?? null));
check('list filters by public=true', [] === $store->list('writings', 50, null, null, true));

/* ------------------------------------------------------------------ *
 *  Publications (public writings API)
 * ------------------------------------------------------------------ */

section('Publications');

$pubRoot = $root . '/pub-memory';
$pubStore = new MemoryStore($pubRoot);
$w1 = $pubStore->create('First public writing.', 'First', 'writings', ['blog'], public: true);
$w2 = $pubStore->create('Second public writing.', 'Second', 'writings', ['blog'], public: true);
$draft = $pubStore->create('A private draft.', 'Draft', 'writings');
$fact = $pubStore->create('A private fact.', 'Fact', 'facts', public: true);

$pubs = new Publications($pubStore);
$listing = $pubs->list();

check('publications total counts only public writings', 2 === $listing['total'], json_encode($listing));
$titles = array_column($listing['writings'], 'title');
check('publications list includes both public writings', in_array('First', $titles, true) && in_array('Second', $titles, true), json_encode($titles));
check('publications list hides drafts and other categories', !in_array('Draft', $titles, true) && !in_array('Fact', $titles, true), json_encode($titles));
check('publications summary omits the body', !array_key_exists('body', $listing['writings'][0] ?? []), json_encode($listing['writings'][0] ?? null));
check('publications summary carries metadata', false === ($listing['writings'][0]['archived'] ?? null) && isset($listing['writings'][0]['tags']), json_encode($listing['writings'][0] ?? null));

check('publications get returns the Markdown body', 'First public writing.' === rtrim((string) ($pubs->get($w1['id'])['markdown'] ?? '')), json_encode($pubs->get($w1['id'])));
check('publications get hides a draft', null === $pubs->get($draft['id']));
check('publications get hides other categories', null === $pubs->get($fact['id']));
check('publications get rejects traversal ids', null === $pubs->get('../secret'));

// Pagination.
check('publications pages with limit/offset', 1 === $pubs->list(1, 0)['count'] && true === $pubs->list(1, 0)['has_more']);
check('publications reports no more past the end', false === $pubs->list(10, 5)['has_more'] && 0 === $pubs->list(10, 5)['count']);

/* ------------------------------------------------------------------ *
 *  RateLimiter
 * ------------------------------------------------------------------ */

section('RateLimiter');

$rlDir = $root . '/ratelimit';
$limiter = new RateLimiter($rlDir, true);
check('allows first request', $limiter->allow('ip1', 3, 60));
check('allows up to the max', $limiter->allow('ip1', 3, 60) && $limiter->allow('ip1', 3, 60));
check('blocks once over the max', !$limiter->allow('ip1', 3, 60));
check('separate keys have separate buckets', $limiter->allow('ip2', 3, 60));

$off = new RateLimiter($rlDir, false);
check('disabled limiter always allows', $off->allow('ip1', 1, 60) && $off->allow('ip1', 1, 60));

/* ------------------------------------------------------------------ *
 *  MemoryIndex (SQLite FTS5; skipped when pdo_sqlite is unavailable)
 * ------------------------------------------------------------------ */

section('MemoryIndex');

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "  SKIP  pdo_sqlite unavailable\n";
} else {
    $idxRoot = $root . '/index-store';
    $idxStore = new MemoryStore($idxRoot);
    $idxStore->create('Alpha body about narwhal.', 'Alpha', 'facts', ['alpha-tag']);
    $idxStore->create('Beta body about narwhal too.', 'Beta', 'facts', ['beta-tag'], archived: true);

    $dbPath = $root . '/index/memory.sqlite';
    @mkdir(dirname($dbPath), 0775, true);

    // Simulate an index built by an older version (no archived column, v1 meta).
    $legacy = new PDO('sqlite:' . $dbPath);
    $legacy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $legacy->exec('CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
    $legacy->exec("CREATE VIRTUAL TABLE memories USING fts5(id UNINDEXED, category UNINDEXED, title, tags, body, tokenize='unicode61')");
    $legacy->exec("INSERT INTO meta (key, value) VALUES ('signature', 'stale')");
    $legacy = null;

    $index = new MemoryIndex($idxStore, $dbPath);
    check('migrates old schema and is available', $index->isAvailable());

    $r = $index->search('narwhal');
    check('search works after migration', 2 === count($r), json_encode(array_column($r, 'id')));

    $r = $index->search('narwhal', archived: true, limit: 1);
    check('archived filter pushed into SQL', 1 === count($r) && true === ($r[0]['archived'] ?? false), json_encode($r));

    $r = $index->search('tag:alpha-tag');
    check('tag-only via FTS matches', 1 === count($r) && 'Alpha' === ($r[0]['title'] ?? ''), json_encode($r));

    $r = $index->search('tag:alpha-tagextra');
    check('tag-only is exact (no prefix match)', [] === $r, json_encode($r));

    $r = $index->search('narwhal tag:beta-tag');
    check('text AND tag requires both on one entry', 1 === count($r) && 'Beta' === ($r[0]['title'] ?? ''), json_encode($r));

    $r = $index->search('alpha tag:beta-tag');
    check('text AND tag with no overlap is empty', [] === $r, json_encode($r));

    // Multi-word and non-ASCII tags (e.g. "ŠC Kranj") must match end-to-end.
    $idxStore->create('Mentorstvo diplomske naloge.', 'Mentorstvo', 'projects', ['diplome', 'ŠC Kranj']);
    $r = $index->search('tag:"ŠC Kranj"');
    check('quoted multi-word non-ASCII tag matches', 1 === count($r) && 'Mentorstvo' === ($r[0]['title'] ?? ''), json_encode($r));
    $r = $index->search('tag:"šc kranj"');
    check('quoted tag is case-insensitive (unicode)', 1 === count($r), json_encode($r));
    $r = $index->search('tag:"ŠC"');
    check('quoted tag stays exact (no partial tag)', [] === $r, json_encode($r));
}

/* ------------------------------------------------------------------ *
 *  Accounts: records, tokens and the guards that keep an admin reachable
 * ------------------------------------------------------------------ */

section('Users: UserStore');

$accountsRoot = $root . '/accounts';
@mkdir($accountsRoot, 0775, true);
$userStore = UserStore::at($accountsRoot);

/** Whether $fn refuses with an InvalidArgumentException (the store's way of saying no). */
$refuses = static function (callable $fn): bool {
    try {
        $fn();
    } catch (\InvalidArgumentException) {
        return true;
    }

    return false;
};

check('users: empty to begin with', $userStore->isEmpty() && 0 === $userStore->count());

$validIds = ['alice', 'bob_2', 'a-b-c', 'x90'];
$invalidIds = ['Al', 'alice?', '../x', 'a/d', '.trash', '-lead', 'ab', str_repeat('a', 33), 'алиса'];
foreach ($validIds as $id) {
    check("users: id '{$id}' accepted", UserStore::isValidId($id));
}
foreach ($invalidIds as $id) {
    check('users: id ' . json_encode($id) . ' refused', !UserStore::isValidId($id));
}

$alice = $userStore->create('alice', 'alice-password', UserStore::ROLE_ADMIN);
check('users: created as an admin', 'admin' === $alice->role && $alice->enabled && !$userStore->isEmpty());
check('users: the password verifies', 'alice' === $userStore->verify('alice', 'alice-password')?->id);
check('users: a wrong password is refused', null === $userStore->verify('alice', 'not-the-password'));
check('users: an unknown account is refused', null === $userStore->verify('nobody', 'alice-password'));
check('users: a duplicate id is refused', $refuses(static fn () => $userStore->create('alice', 'another-password')));
check('users: a short password is refused', $refuses(static fn () => $userStore->create('carol', 'short')));
check('users: an unknown role is refused', $refuses(static fn () => $userStore->create('carol', 'carol-password', 'superuser')));
check('users: only a hash is stored', !str_contains((string) file_get_contents($userStore->path()), 'alice-password'));

// API tokens: one per API per account, generated here so nobody picks a weak one.
$epoch = $userStore->get('alice')->authEpoch;
$writingsToken = $userStore->rotateToken('alice', UserStore::TOKEN_WRITINGS);
check('users: a token looks random', 1 === preg_match('/^[0-9a-f]{48}$/', $writingsToken), $writingsToken);
check('users: rotating a token does not sign the account out', $epoch === $userStore->get('alice')->authEpoch);
check('users: the token resolves to its account', 'alice' === $userStore->verifyToken(UserStore::TOKEN_WRITINGS, $writingsToken)?->id);
check('users: it does not work for the other API', null === $userStore->verifyToken(UserStore::TOKEN_WORKFLOWS, $writingsToken));
check('users: an empty token resolves to nobody', null === $userStore->verifyToken(UserStore::TOKEN_WRITINGS, ''));
check('users: an unknown token kind is refused', $refuses(static fn () => $userStore->rotateToken('alice', 'passwords')));
$userStore->clearToken('alice', UserStore::TOKEN_WRITINGS);
check('users: a cleared token stops working', null === $userStore->verifyToken(UserStore::TOKEN_WRITINGS, $writingsToken));

// The auth epoch is what invalidates sessions and OAuth tokens downstream.
$userStore->setPassword('alice', 'alice-password-2');
check('users: a password change bumps the epoch', $userStore->get('alice')->authEpoch > $epoch);
check('users: the new password verifies', null !== $userStore->verify('alice', 'alice-password-2'));
check('users: the old password does not', null === $userStore->verify('alice', 'alice-password'));

// An instance with no enabled admin has no way back except editing the file by
// hand, so the store refuses to get there.
check('users: the only admin cannot be demoted', $refuses(static fn () => $userStore->setRole('alice', UserStore::ROLE_USER)));
check('users: the only admin cannot be disabled', $refuses(static fn () => $userStore->setEnabled('alice', false)));
check('users: the only admin cannot be deleted', $refuses(static fn () => $userStore->delete('alice')));
check('users: still an enabled admin after those refusals', true === $userStore->get('alice')?->enabled && 1 === $userStore->enabledAdmins());

$userStore->create('bob', 'bob-password');
check('users: a second account starts as a plain user', 'user' === $userStore->get('bob')?->role);
$bobToken = $userStore->rotateToken('bob', UserStore::TOKEN_WRITINGS);
$userStore->setEnabled('bob', false);
check('users: a disabled account is not resolved by its token', null === $userStore->verifyToken(UserStore::TOKEN_WRITINGS, $bobToken));
check('users: a disabled account can still be told it is disabled', null !== $userStore->verify('bob', 'bob-password'));
$userStore->setRole('bob', UserStore::ROLE_ADMIN);
check('users: a disabled admin does not count as the way back in', $refuses(static fn () => $userStore->setRole('alice', UserStore::ROLE_USER)));
$userStore->setEnabled('bob', true);
$userStore->setRole('alice', UserStore::ROLE_USER);
check('users: with a second enabled admin the first can be demoted', 'user' === $userStore->get('alice')?->role);
$userStore->setRole('alice', UserStore::ROLE_ADMIN);
check('users: deleting removes the record', null !== $userStore->delete('bob') && !$userStore->has('bob'));
check('users: the account left behind is untouched', 'admin' === $userStore->get('alice')?->role && $userStore->get('alice')?->enabled === true);

/* ------------------------------------------------------------------ *
 *  Accounts: paths, legacy adoption, and the delete that keeps the data
 * ------------------------------------------------------------------ */

section('Users: paths and directories');

$userPaths = new UserPaths($root . '/users');
$slashed = static fn (string $path): string => str_replace('\\', '/', $path);

check('paths: memory is per account', str_ends_with($slashed($userPaths->memory('alice')), '/users/alice/memory'), $userPaths->memory('alice'));
check('paths: the index is per account', str_ends_with($slashed($userPaths->indexPath('alice')), '/users/alice/index/memory.sqlite'));
check('paths: two accounts never share a directory', $userPaths->memory('alice') !== $userPaths->memory('bob'));
check('paths: the deleted-accounts directory is a dot directory', str_ends_with($slashed($userPaths->trashRoot()), '/users/.trash'));
foreach (['../alice', 'Alice', '', '.trash', 'a/b'] as $badId) {
    check('paths: refuses id ' . json_encode($badId), $refuses(static fn () => $userPaths->memory($badId)));
}

$directories = new UserDirectories($userPaths);

// A legacy single-user corpus: two categories, a trash, and a root README.
$legacy = $root . '/legacy-memory';
@mkdir($legacy . '/facts', 0775, true);
@mkdir($legacy . '/.trash', 0775, true);
@mkdir($legacy . '/not-a-category dir', 0775, true);
file_put_contents($legacy . '/facts/one.md', "# One\n");
file_put_contents($legacy . '/.trash/20260101T000000Z__facts__gone.md', "# Gone\n");
file_put_contents($legacy . '/README.md', "# Legacy readme\n");

$adoption = $directories->adoptLegacyCorpus('alice', $legacy);
check('legacy: a category directory moves', in_array('facts', $adoption['moved'], true) && is_file($userPaths->memory('alice') . '/facts/one.md'));
check('legacy: the trash moves too', is_dir($userPaths->memory('alice') . '/.trash'));
check('legacy: a directory that is not a category stays put', is_dir($legacy . '/not-a-category dir'));
check('legacy: the root README is not memory and is left alone', is_file($legacy . '/README.md') && !is_file($userPaths->memory('alice') . '/README.md'));

$again = $directories->adoptLegacyCorpus('alice', $legacy);
check('legacy: re-running moves nothing', [] === $again['moved'] && [] === $again['skipped'], json_encode($again));

// Nothing is lost when an account is deleted: the directory is parked.
$parked = $directories->archive('alice');
check('archive: the directory moves aside', null !== $parked && is_dir((string) $parked) && !is_dir($userPaths->userRoot('alice')));
check('archive: it is under .trash and names the account', str_contains($slashed((string) $parked), '/users/.trash/') && str_ends_with((string) $parked, '__alice'), (string) $parked);
check('archive: every memory travels with it', is_file((string) $parked . '/memory/facts/one.md'));
check('archive: it can be put back', $directories->restore((string) $parked, 'alice') && is_file($userPaths->memory('alice') . '/facts/one.md'));
check('archive: an account that never had a directory archives to null', null === $directories->archive('nobody'));

/* ------------------------------------------------------------------ *
 *  Accounts: the one-time upgrade from a single-user instance
 * ------------------------------------------------------------------ */

section('Users: migration');

$migRoot = $root . '/migrate';
@mkdir($migRoot . '/auth', 0775, true);
@mkdir($migRoot . '/legacy/preferences', 0775, true);
file_put_contents($migRoot . '/legacy/preferences/p.md', "# Preference\n");

$migUsers = UserStore::at($migRoot . '/auth');
$migPaths = new UserPaths($migRoot . '/users');
$migDirs = new UserDirectories($migPaths);
$migConfig = new Config(array_replace($minimal, [
    'data_path' => $migRoot,
    'users_path' => $migRoot . '/users',
    'memory_path' => $migRoot . '/legacy',
    'oauth_username' => 'owner',
    'admin_password_hash' => password_hash('migrated-password', PASSWORD_DEFAULT),
]));
$migration = new Migration($migConfig, $migUsers, $migPaths, $migDirs);

$migration->run();
check('migration: seeds the first admin from the environment', 'admin' === $migUsers->get('owner')?->role);
check('migration: with the environment password', null !== $migUsers->verify('owner', 'migrated-password'));
check('migration: adopts the legacy corpus', is_file($migPaths->memory('owner') . '/preferences/p.md'));
check('migration: the old directory is not left half-moved', !is_dir($migRoot . '/legacy/preferences'));
check('migration: records that it ran', is_file($migRoot . '/auth/migration.json'));

$migration->run();
check('migration: running again changes nothing', 1 === $migUsers->count());

// Deleting the account store must not resurrect an admin from stale env vars:
// seeding happened once, and the way back in is /ui/setup.
file_put_contents($migRoot . '/auth/users.json', '{}');
$migration->run();
check('migration: a cleared account store is not re-seeded', $migUsers->isEmpty());

// Where the environment has no UI password, the hash a previous version wrote
// on first run is used; and a plaintext consent password is hashed.
$legacyRoot = $root . '/migrate-legacy-ui';
@mkdir($legacyRoot . '/auth', 0775, true);
file_put_contents(
    $legacyRoot . '/auth/ui.json',
    json_encode(['admin' => ['password_hash' => password_hash('ui-json-password', PASSWORD_DEFAULT)]])
);
$legacyConfig = new Config(array_replace($minimal, [
    'data_path' => $legacyRoot,
    'memory_path' => $legacyRoot . '/nothing',
]));
$legacyUsers = UserStore::at($legacyRoot . '/auth');
(new Migration($legacyConfig, $legacyUsers, new UserPaths($legacyRoot . '/users'), new UserDirectories(new UserPaths($legacyRoot . '/users'))))->run();
check('migration: falls back to the hash a previous version stored', null !== $legacyUsers->verify('admin', 'ui-json-password'));

$consentRoot = $root . '/migrate-consent';
@mkdir($consentRoot . '/auth', 0775, true);
$consentConfig = new Config(array_replace($minimal, [
    'data_path' => $consentRoot,
    'memory_path' => $consentRoot . '/nothing',
    'admin_password_hash' => '',
    'oauth_consent_password' => 'plaintext-consent-password',
]));
$consentUsers = UserStore::at($consentRoot . '/auth');
(new Migration($consentConfig, $consentUsers, new UserPaths($consentRoot . '/users'), new UserDirectories(new UserPaths($consentRoot . '/users'))))->run();
check('migration: a plaintext consent password is hashed on the way in', null !== $consentUsers->verify('admin', 'plaintext-consent-password'));
check('migration: and stored only as a hash', !str_contains((string) file_get_contents($consentRoot . '/auth/users.json'), 'plaintext-consent-password'));

// A fresh install has nothing to seed: /ui/setup creates the first admin.
$emptyRoot = $root . '/migrate-empty';
@mkdir($emptyRoot . '/auth', 0775, true);
$emptyConfig = new Config(array_replace($minimal, ['data_path' => $emptyRoot, 'memory_path' => $emptyRoot . '/nothing']));
$emptyUsers = UserStore::at($emptyRoot . '/auth');
(new Migration($emptyConfig, $emptyUsers, new UserPaths($emptyRoot . '/users'), new UserDirectories(new UserPaths($emptyRoot . '/users'))))->run();
check('migration: a fresh install seeds nothing', $emptyUsers->isEmpty() && !is_file($emptyRoot . '/auth/migration.json'));

/* ------------------------------------------------------------------ *
 *  Auth: tokens are subject-bound
 * ------------------------------------------------------------------ */

section('Auth: token subjects');

@mkdir($root . '/token-auth', 0775, true);
$tokenStore = new TokenStore($root . '/token-auth', 3600, 86400, 600);
$code = $tokenStore->createCode('client-1', 'https://client.test/cb', str_repeat('a', 43), [TokenStore::SCOPE], 'https://md.test/mcp', 'alice', 3);
$codeRecord = $tokenStore->consumeCode($code);
check('tokens: a code carries the account that consented', 'alice' === ($codeRecord['sub'] ?? '') && 3 === ($codeRecord['epoch'] ?? 0), json_encode($codeRecord));

$access = $tokenStore->createAccessToken('client-1', [TokenStore::SCOPE], 'https://md.test/mcp', 'alice', 3);
$accessRecord = $tokenStore->validateAccessToken($access);
check('tokens: an access token carries it too', 'alice' === ($accessRecord['sub'] ?? '') && 3 === ($accessRecord['epoch'] ?? 0), json_encode($accessRecord));

$refresh = $tokenStore->createRefreshToken('client-1', [TokenStore::SCOPE], 'https://md.test/mcp', 'alice', 3);
$rotatedToken = $tokenStore->rotateRefreshToken($refresh);
check('tokens: rotation keeps the account', 'alice' === ($rotatedToken['sub'] ?? '') && 3 === ($rotatedToken['epoch'] ?? 0), json_encode($rotatedToken));

check('tokens: no plaintext token is written to disk', !str_contains((string) file_get_contents($root . '/token-auth/tokens.json'), $access));
check('tokens: revoking an account drops its records', 2 === $tokenStore->revokeAllForUser('alice'));
check('tokens: the revoked access token no longer validates', null === $tokenStore->validateAccessToken($access));

/* ------------------------------------------------------------------ *
 *  HTTP: reading a bearer token
 * ------------------------------------------------------------------ */

section('Support: bearer tokens');

$httpFactory = new \Nyholm\Psr7\Factory\Psr17Factory();
$bearerRequest = $httpFactory->createServerRequest('GET', '/mcp');
check('bearer: absent header is empty', '' === BearerToken::fromRequest($bearerRequest));
check('bearer: extracted', 'abc123' === BearerToken::fromRequest($bearerRequest->withHeader('Authorization', 'Bearer abc123')));
check('bearer: scheme is case-insensitive', 'abc123' === BearerToken::fromRequest($bearerRequest->withHeader('Authorization', 'bEaReR   abc123  ')));
check('bearer: another scheme is not a bearer', '' === BearerToken::fromRequest($bearerRequest->withHeader('Authorization', 'Basic abc123')));
check('bearer: an empty value is empty', '' === BearerToken::fromRequest($bearerRequest->withHeader('Authorization', 'Bearer ')));

/* ------------------------------------------------------------------ *
 *  Summary
 * ------------------------------------------------------------------ */

echo "\n" . str_repeat('-', 48) . "\n";
echo "Passed: {$passed}  Failed: {$failed}\n";
if ([] !== $failures) {
    echo "\nFailures:\n";
    foreach ($failures as $failure) {
        echo "  - {$failure}\n";
    }
}

exit(0 === $failed ? 0 : 1);
