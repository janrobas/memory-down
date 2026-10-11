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

use MemoryDown\Memory\Frontmatter;
use MemoryDown\Memory\MemoryIndex;
use MemoryDown\Memory\MemoryStore;
use MemoryDown\Memory\PathValidator;
use MemoryDown\Memory\RecommendedTags;
use MemoryDown\Memory\SearchQuery;
use MemoryDown\Publish\Publications;
use MemoryDown\Support\RateLimiter;

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
$store = new MemoryStore($memRoot, $root . '/auth');
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

/* ------------------------------------------------------------------ *
 *  MemoryStore: the trash
 * ------------------------------------------------------------------ */

section('MemoryStore: trash');

$trashRoot = $root . '/trash-store';
$trashStore = new MemoryStore($trashRoot, $root . '/trash-auth');
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
$pubStore = new MemoryStore($pubRoot, $root . '/pub-auth');
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
    $idxStore = new MemoryStore($idxRoot, $root . '/index-auth');
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
