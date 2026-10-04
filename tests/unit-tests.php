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
use MemoryDown\Memory\SearchQuery;
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
