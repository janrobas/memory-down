<?php

declare(strict_types=1);

namespace MemoryDown\Web;

use MemoryDown\Config;
use MemoryDown\Kernel;
use MemoryDown\Memory\MemoryIndex;
use MemoryDown\Memory\MemoryStore;
use MemoryDown\Memory\PathValidator;
use MemoryDown\Memory\SearchEngine;
use MemoryDown\Support\ZipWriter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * The human admin UI: browse, search and edit the Markdown memory store.
 *
 * Server-rendered shell (works without JS) plus a tiny JSON API under
 * /ui/api/* that the progressive-enhancement JS uses for live search and
 * in-place editing. All storage goes through the Memory layer — this class
 * never touches files itself.
 */
final class WebApp
{
    public const ADMIN_HEADER = 'X-MemoryDown-Admin';

    /** An entry is flagged "new" when its Markdown file was modified this recently. */
    private const NEW_WINDOW_SECONDS = 86400;

    /**
     * Flash messages for the trash page, keyed by a short code passed in the
     * query string. A fixed vocabulary means nothing the user typed — or a link
     * someone else crafted — is ever echoed back into the page.
     */
    private const NOTICES = [
        'restored' => 'Memory restored to its original category.',
        'purged' => 'Trashed entry deleted permanently.',
        'emptied' => 'Trash emptied. Those entries are gone for good.',
        'invalid' => 'That entry is no longer in the trash.',
        'restore_failed' => 'Could not restore that entry: a memory with the same id exists again, or the file could not be moved. Reload and check.',
    ];

    /** Shipped inside every backup zip so it explains itself later. */
    private const RESTORE_NOTES = <<<'TXT'
    MemoryDown backup
    =================

    This archive contains every file of the memory store under memory/, exactly as
    it sits on disk. The Markdown files are the source of truth; nothing here is a
    database dump and nothing needs importing.

    To restore
    ----------

    1. Unzip this archive anywhere.
    2. Upload the memory/ folder so that its contents land in MEMORY_PATH
       (by default data/memory), keeping the category subfolders.
    3. Done. The search index rebuilds itself from the files on the next search
       (or press Reindex in the admin UI).

    Notes
    -----

    - Only memory/ is included. Restoring it brings back every memory; it does not
      restore OAuth clients or tokens (data/auth) or your .env, so after a full
      rebuild you will re-enter those and reconnect your MCP clients.
    - index/, sessions/ and logs/ are deliberately absent: the index is disposable,
      the rest is regenerable.
    - The .trash folder inside memory/ holds entries deleted before this backup was
      taken. Restore them from the admin UI if you want them back.
    TXT;

    private Psr17Factory $factory;
    private WebAuth $auth;

    public function __construct(
        private readonly Config $config,
        private readonly MemoryStore $store,
        private readonly SearchEngine $search,
        private readonly LoggerInterface $logger,
    ) {
        $this->factory = new Psr17Factory();
        $this->auth = new WebAuth($config, $logger);
    }

    public static function fromKernel(): self
    {
        $kernel = Kernel::get();

        return new self($kernel->config, $kernel->memory, $kernel->search, $kernel->logger);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->config->uiEnabled) {
            return $this->json(['error' => 'ui_disabled'], 404);
        }

        $this->auth->start();

        $path = rtrim('/' . trim($request->getUri()->getPath(), '/'), '/');
        if ('' === $path) {
            $path = '/ui';
        }
        $method = strtoupper($request->getMethod());

        try {
            return match (true) {
                '/ui' === $path && 'GET' === $method => $this->page($request),
                '/ui/login' === $path && 'GET' === $method => $this->loginPage(),
                '/ui/login' === $path && 'POST' === $method => $this->loginSubmit($request),
                '/ui/logout' === $path && 'POST' === $method => $this->logout($request),
                '/ui/setup' === $path && 'GET' === $method => $this->setupPage(),
                '/ui/setup' === $path && 'POST' === $method => $this->setupSubmit($request),
                '/ui/reindex' === $path && 'POST' === $method => $this->reindex($request),
                '/ui/backup' === $path && 'GET' === $method => $this->backup(),
                '/ui/trash' === $path && 'GET' === $method => $this->trashPage($request),
                '/ui/trash/restore' === $path && 'POST' === $method => $this->trashRestore($request),
                '/ui/trash/purge' === $path && 'POST' === $method => $this->trashPurge($request),
                '/ui/trash/empty' === $path && 'POST' === $method => $this->trashEmpty($request),
                '/ui/api/tree' === $path && 'GET' === $method => $this->apiTree(),
                '/ui/api/search' === $path && 'GET' === $method => $this->apiSearch($request),
                '/ui/api/memory' === $path && 'GET' === $method => $this->apiGet($request),
                '/ui/api/memory' === $path && 'POST' === $method => $this->apiSave($request),
                '/ui/api/memory' === $path && 'DELETE' === $method => $this->apiDelete($request),
                '/ui/api/preview' === $path && 'POST' === $method => $this->apiPreview($request),
                default => $this->json(['error' => 'not_found'], 404),
            };
        } catch (\Throwable $e) {
            $this->logger->error('admin.unhandled_exception', ['message' => $e->getMessage(), 'class' => $e::class]);

            return $this->json(['error' => 'internal_error'], 500);
        }
    }

    /* ------------------------------------------------------------------ *
     *  Pages
     * ------------------------------------------------------------------ */

    private function page(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->auth->isConfigured()) {
            return $this->redirect('/ui/setup');
        }
        if (!$this->auth->loggedIn()) {
            return $this->redirect('/ui/login');
        }

        $query = $request->getQueryParams();
        $selected = null;
        $category = (string) ($query['category'] ?? '');
        $id = (string) ($query['id'] ?? '');
        if (PathValidator::isCategory($category) && PathValidator::isId($id)) {
            $selected = $this->store->read($category, $id);
        }

        return $this->html($this->view('app', [
            'title' => 'MemoryDown',
            'bodyClass' => 'app',
            'csrf' => $this->auth->csrfToken(),
            'categories' => $this->store->categories(),
            'tree' => $this->tree(),
            'selected' => $selected,
            'trashCount' => $this->store->trashCount(),
            'engine' => $this->search instanceof MemoryIndex ? $this->search->status() : ['engine' => 'direct'],
        ]));
    }

    private function loginPage(): ResponseInterface
    {
        if (!$this->auth->isConfigured()) {
            return $this->redirect('/ui/setup');
        }
        if ($this->auth->loggedIn()) {
            return $this->redirect('/ui');
        }

        return $this->html($this->view('login', [
            'title' => 'Sign in',
            'bodyClass' => 'auth',
            'csrf' => $this->auth->csrfToken(),
            'error' => '',
        ]));
    }

    private function loginSubmit(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->auth->isConfigured()) {
            return $this->redirect('/ui/setup');
        }

        $body = $request->getParsedBody() ?? [];
        if (!$this->auth->checkCsrf($this->str($body['csrf'] ?? null))) {
            return $this->html($this->view('login', [
                'title' => 'Sign in',
                'bodyClass' => 'auth',
                'csrf' => $this->auth->csrfToken(),
                'error' => 'Your session expired. Please try again.',
            ]), 400);
        }

        if (!$this->auth->login($this->str($body['password'] ?? null))) {
            return $this->html($this->view('login', [
                'title' => 'Sign in',
                'bodyClass' => 'auth',
                'csrf' => $this->auth->csrfToken(),
                'error' => 'Incorrect password (or too many attempts — wait a moment).',
            ]), 401);
        }

        return $this->redirect('/ui');
    }

    private function logout(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody() ?? [];
        $token = $request->getHeaderLine('X-CSRF-Token');
        if ('' === $token) {
            $token = $this->str($body['csrf'] ?? null);
        }
        if ($this->auth->checkCsrf($token)) {
            $this->auth->logout();
        }

        return $this->redirect('/ui/login');
    }

    private function setupPage(): ResponseInterface
    {
        if ($this->auth->isConfigured()) {
            return $this->redirect('/ui/login');
        }

        return $this->html($this->view('setup', [
            'title' => 'Set up MemoryDown',
            'bodyClass' => 'auth',
            'csrf' => $this->auth->csrfToken(),
            'needsToken' => '' !== $this->config->adminSetupToken,
            'error' => '',
        ]));
    }

    private function setupSubmit(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->auth->isConfigured()) {
            return $this->redirect('/ui/login');
        }

        $body = $request->getParsedBody() ?? [];
        $error = '';
        $password = $this->str($body['password'] ?? null);
        $confirm = $this->str($body['confirm'] ?? null);
        $token = $this->str($body['setup_token'] ?? null);

        if (!$this->auth->checkCsrf($this->str($body['csrf'] ?? null))) {
            $error = 'Your session expired. Please try again.';
        } elseif ('' !== $this->config->adminSetupToken && !hash_equals($this->config->adminSetupToken, $token)) {
            $error = 'Invalid setup token.';
        } elseif (strlen($password) < 8) {
            $error = 'Choose a password of at least 8 characters.';
        } elseif ($password !== $confirm) {
            $error = 'The two passwords do not match.';
        }

        if ('' !== $error) {
            return $this->html($this->view('setup', [
                'title' => 'Set up MemoryDown',
                'bodyClass' => 'auth',
                'csrf' => $this->auth->csrfToken(),
                'needsToken' => '' !== $this->config->adminSetupToken,
                'error' => $error,
            ]), 400);
        }

        $this->auth->setPassword($password);
        $this->auth->login($password);

        return $this->redirect('/ui');
    }

    /* ------------------------------------------------------------------ *
     *  JSON API
     * ------------------------------------------------------------------ */

    private function apiTree(): ResponseInterface
    {
        $this->auth->start();

        return $this->json(['categories' => $this->tree(), 'total' => $this->store->count()]);
    }

    private function apiSearch(ServerRequestInterface $request): ResponseInterface
    {
        $this->auth->start();

        $query = $request->getQueryParams();
        $q = trim((string) ($query['q'] ?? ''));
        $category = (string) ($query['category'] ?? '');
        $limit = (int) ($query['limit'] ?? 30);
        $tag = trim((string) ($query['tag'] ?? ''));

        if ('' === $q && '' === $tag) {
            return $this->json(['results' => []]);
        }

        $results = $this->search->search(
            $q,
            PathValidator::isCategory($category) ? $category : null,
            min(max(1, $limit), 50),
            false,
            '' !== $tag ? $tag : null,
            self::archivedFilter((string) ($query['archived'] ?? 'all')),
        );

        $light = array_map(fn (array $r): array => [
            'id' => $r['id'],
            'category' => $r['category'],
            'title' => $r['title'],
            'updated' => $r['updated'] ?? '',
            'tags' => $r['tags'] ?? [],
            'archived' => (bool) ($r['archived'] ?? false),
            'public' => (bool) ($r['public'] ?? false),
            'new' => $this->isNew($r),
            'score' => $r['score'] ?? 0,
            'snippet' => $r['snippet'] ?? '',
        ], $results);

        return $this->json(['results' => $light]);
    }

    private function apiGet(ServerRequestInterface $request): ResponseInterface
    {
        $this->auth->start();

        $query = $request->getQueryParams();
        $category = (string) ($query['category'] ?? '');
        $id = (string) ($query['id'] ?? '');
        if (!PathValidator::isCategory($category) || !PathValidator::isId($id)) {
            return $this->json(['error' => 'invalid_request'], 400);
        }

        $doc = $this->store->read($category, $id);
        if (null === $doc) {
            return $this->json(['error' => 'not_found'], 404);
        }

        return $this->json(['memory' => $doc]);
    }

    private function apiSave(ServerRequestInterface $request): ResponseInterface
    {
        $this->auth->start();
        if (!$this->guardMutation($request)) {
            return $this->json(['error' => 'forbidden'], 403);
        }

        $data = $this->input($request);
        $category = (string) ($data['category'] ?? '');
        $id = (string) ($data['id'] ?? '');
        $title = trim((string) ($data['title'] ?? ''));
        $content = (string) ($data['content'] ?? '');
        $tags = $this->normalizeTags($data['tags'] ?? []);
        $archived = filter_var($data['archived'] ?? false, FILTER_VALIDATE_BOOL);
        $public = filter_var($data['public'] ?? false, FILTER_VALIDATE_BOOL);

        if (!PathValidator::isCategory($category)) {
            return $this->json(['error' => 'Invalid category.'], 400);
        }
        if ('' === trim($content)) {
            return $this->json(['error' => 'Content must not be empty.'], 400);
        }

        try {
            if ('' === $id) {
                $doc = $this->store->create($content, $title, $category, $tags, archived: $archived, public: $public);
            } else {
                if (!PathValidator::isId($id)) {
                    return $this->json(['error' => 'Invalid id.'], 400);
                }
                $changes = [
                    'title' => $title,
                    'content' => $content,
                    'tags' => $tags,
                    'category' => $category,
                    'archived' => $archived,
                ];
                // Only touch the publish flag when the client sent it, so an
                // older client can never accidentally unpublish a writing.
                if (array_key_exists('public', $data)) {
                    $changes['public'] = $public;
                }
                $doc = $this->store->update($this->categoryFor($id), $id, $changes);
            }
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }

        return $this->json(['memory' => $doc]);
    }

    private function apiDelete(ServerRequestInterface $request): ResponseInterface
    {
        $this->auth->start();
        if (!$this->guardMutation($request)) {
            return $this->json(['error' => 'forbidden'], 403);
        }

        $query = $request->getQueryParams();
        $category = (string) ($query['category'] ?? '');
        $id = (string) ($query['id'] ?? '');
        if (!PathValidator::isCategory($category) || !PathValidator::isId($id)) {
            return $this->json(['error' => 'invalid_request'], 400);
        }

        if (!$this->store->delete($category, $id)) {
            return $this->json(['error' => 'not_found'], 404);
        }

        return $this->json(['ok' => true, 'id' => $id]);
    }

    private function apiPreview(ServerRequestInterface $request): ResponseInterface
    {
        $this->auth->start();
        $data = $this->input($request);

        return $this->json(['html' => Markdown::toHtml((string) ($data['markdown'] ?? ''))]);
    }

    /* ------------------------------------------------------------------ *
     *  Trash & backup
     * ------------------------------------------------------------------ */

    /**
     * Show the trash: everything deleted, newest first, with the original
     * category and id read back from the filename.
     */
    private function trashPage(ServerRequestInterface $request): ResponseInterface
    {
        $this->auth->start();
        if (!$this->auth->isConfigured()) {
            return $this->redirect('/ui/setup');
        }
        if (!$this->auth->loggedIn()) {
            return $this->redirect('/ui/login');
        }

        $query = $request->getQueryParams();
        $entries = $this->store->trashed();
        $bytes = 0;
        foreach ($entries as $entry) {
            $bytes += $entry['bytes'];
        }

        return $this->html($this->view('trash', [
            'title' => 'MemoryDown — Trash',
            'bodyClass' => 'trash',
            'csrf' => $this->auth->csrfToken(),
            'entries' => $entries,
            'bytes' => $bytes,
            'notice' => self::NOTICES[$this->str($query['notice'] ?? null)] ?? '',
            'error' => self::NOTICES[$this->str($query['error'] ?? null)] ?? '',
        ]));
    }

    private function trashRestore(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->guardMutation($request)) {
            return $this->json(['error' => 'forbidden'], 403);
        }

        $name = $this->str($this->input($request)['name'] ?? null);
        if (null === $this->store->paths()->trashFile($name)) {
            return $this->redirect('/ui/trash?error=invalid');
        }

        try {
            $this->store->restore($name);
        } catch (\Throwable $e) {
            // The reason is logged, never echoed: the page shows a fixed
            // message so a crafted link cannot put words in the admin's mouth.
            $this->logger->warning('admin.trash_restore_failed', ['message' => $e->getMessage()]);

            return $this->redirect('/ui/trash?error=restore_failed');
        }

        return $this->redirect('/ui/trash?notice=restored');
    }

    private function trashPurge(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->guardMutation($request)) {
            return $this->json(['error' => 'forbidden'], 403);
        }

        $name = $this->str($this->input($request)['name'] ?? null);

        return $this->redirect('/ui/trash?' . ($this->store->purge($name) ? 'notice=purged' : 'error=invalid'));
    }

    private function trashEmpty(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->guardMutation($request)) {
            return $this->json(['error' => 'forbidden'], 403);
        }

        $this->store->purgeTrash();

        return $this->redirect('/ui/trash?notice=emptied');
    }

    /**
     * Download every Markdown file in the memory store as a zip.
     *
     * Deliberately memory only: no auth state and no .env, so the archive can
     * never leak credentials — restoring it rebuilds the whole corpus from the
     * canonical files, and the search index rebuilds itself on the next search.
     *
     * A plain GET (no CSRF token): it changes nothing, and although a hostile
     * page could make the browser start this download, it cannot read the
     * response — the bytes land on the user's own disk.
     */
    private function backup(): ResponseInterface
    {
        $this->auth->start();
        if (!$this->auth->isConfigured()) {
            return $this->redirect('/ui/setup');
        }
        if (!$this->auth->loggedIn()) {
            return $this->redirect('/ui/login');
        }

        $files = $this->memoryFiles();
        $zip = new ZipWriter();

        foreach ($files as $relative => $absolute) {
            $contents = @file_get_contents($absolute);
            if (false === $contents) {
                continue;
            }
            $zip->add('memory/' . $relative, $contents, (int) @filemtime($absolute));
        }

        $zip->add('MANIFEST.json', json_encode([
            'application' => $this->config->appName,
            'version' => $this->config->appVersion,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'php' => PHP_VERSION,
            'memories' => $this->store->count(),
            'files' => count($files),
            'contents' => 'memory/** plus this manifest and RESTORE.txt',
            'excluded' => 'data/index (disposable, rebuilds itself), data/sessions, data/logs, data/auth and .env',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $zip->add('RESTORE.txt', self::RESTORE_NOTES);

        return $this->download(
            'memorydown-backup-' . gmdate('Y-m-d-Hi') . '.zip',
            'application/zip',
            $zip->finish()
        );
    }

    /**
     * Every file under the memory root, relative path => absolute path. Includes
     * the trash and the root README: a backup should be a faithful copy of the
     * directory, not just the parts the app happens to show.
     *
     * @return array<string, string>
     */
    private function memoryFiles(): array
    {
        $root = $this->store->paths()->root();
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
            $files[$relative] = $path;
        }

        ksort($files);

        return $files;
    }


    private function reindex(ServerRequestInterface $request): ResponseInterface
    {
        $this->auth->start();
        if (!$this->guardMutation($request)) {
            return $this->json(['error' => 'forbidden'], 403);
        }

        if ($this->search instanceof MemoryIndex) {
            $this->search->rebuild();

            return $this->json(['ok' => true, 'status' => $this->search->status()]);
        }

        return $this->json(['ok' => true, 'status' => ['engine' => 'direct']]);
    }

    /* ------------------------------------------------------------------ *
     *  Helpers
     * ------------------------------------------------------------------ */

    /**
     * @return list<array{name: string, memories: list<array<string, mixed>>}>
     */
    private function tree(): array
    {
        $categories = $this->store->categories();
        if ([] === $categories) {
            $categories = PathValidator::DEFAULT_CATEGORIES;
        }

        $grouped = [];
        foreach ($categories as $category) {
            $grouped[$category] = [];
        }

        foreach ($this->store->list(null, 1000) as $doc) {
            $category = (string) $doc['category'];
            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
                $categories[] = $category;
            }
            $grouped[$category][] = [
                'id' => $doc['id'],
                'title' => $doc['title'],
                'updated' => $doc['updated'] ?? '',
                'tags' => $doc['tags'] ?? [],
                'archived' => (bool) ($doc['archived'] ?? false),
                'public' => (bool) ($doc['public'] ?? false),
                'new' => $this->isNew($doc),
            ];
        }

        // Show freshly modified entries first within each category (stable sort:
        // non-new entries keep their updated-desc order).
        foreach ($grouped as $category => $memories) {
            usort($memories, static fn (array $a, array $b): int
                => (int) ($b['new'] ?? false) <=> (int) ($a['new'] ?? false));
            $grouped[$category] = $memories;
        }

        sort($categories);
        $tree = [];
        foreach ($categories as $category) {
            $tree[] = ['name' => $category, 'memories' => $grouped[$category] ?? []];
        }

        return $tree;
    }

    private function guardMutation(ServerRequestInterface $request): bool
    {
        if (!$this->auth->loggedIn()) {
            return false;
        }

        $token = $request->getHeaderLine('X-CSRF-Token');
        if ('' === $token) {
            $data = $this->input($request);
            $token = $this->str($data['csrf'] ?? null);
        }

        return $this->auth->checkCsrf($token);
    }

    /**
     * @return array<string, mixed>
     */
    private function input(ServerRequestInterface $request): array
    {
        $contentType = strtolower($request->getHeaderLine('Content-Type'));
        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode((string) $request->getBody(), true);

            return is_array($decoded) ? $decoded : [];
        }

        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * @param mixed $value
     *
     * @return list<string>
     */
    private function normalizeTags(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }

        $tags = [];
        foreach ($value as $tag) {
            $tag = trim((string) $tag);
            if ('' !== $tag) {
                $tags[] = $tag;
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * Map a tri-state "all|active|archived" selector to a nullable boolean
     * filter (null = all).
     */
    private static function archivedFilter(string $value): ?bool
    {
        return match (strtolower(trim($value))) {
            'archived' => true,
            'active' => false,
            default => null,
        };
    }

    private function categoryFor(string $id): string
    {
        foreach ($this->store->categories() as $category) {
            if (null !== $this->store->read($category, $id)) {
                return $category;
            }
        }

        return $this->store->categories()[0] ?? 'facts';
    }

    /**
     * Whether a memory counts as "new": it was written within the last
     * NEW_WINDOW_SECONDS, according to the stored ISO-8601 timestamp
     * (`updated_ts`, derived from the frontmatter `updated` field, falling back
     * to the file mtime for files without one). Archived entries are never new.
     *
     * @param array<string, mixed> $doc
     */
    private function isNew(array $doc): bool
    {
        if (!empty($doc['archived'])) {
            return false;
        }

        $ts = (int) ($doc['updated_ts'] ?? 0);

        return $ts > 0 && $ts >= time() - self::NEW_WINDOW_SECONDS;
    }

    private function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    public static function h(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function view(string $template, array $data): string
    {
        $content = (static function () use ($template, $data): string {
            extract($data, EXTR_SKIP);
            ob_start();
            include __DIR__ . '/views/' . $template . '.php';

            return (string) ob_get_clean();
        })();

        extract($data, EXTR_SKIP);
        ob_start();
        include __DIR__ . '/views/layout.php';

        return (string) ob_get_clean();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200): ResponseInterface
    {
        return $this->respond($status, 'application/json; charset=utf-8', json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) ?: '{}');
    }

    private function html(string $html, int $status = 200): ResponseInterface
    {
        return $this->respond($status, 'text/html; charset=utf-8', $html);
    }

    /**
     * A response the browser saves to disk instead of rendering. Never cached:
     * the body is a complete copy of the user's memory.
     */
    private function download(string $filename, string $contentType, string $body): ResponseInterface
    {
        return $this->respond(200, $contentType, $body)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string) strlen($body));
    }

    private function respond(int $status, string $contentType, string $body): ResponseInterface
    {
        $response = $this->factory->createResponse($status)
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader(self::ADMIN_HEADER, '1');
        $response->getBody()->write($body);

        return $response;
    }

    private function redirect(string $to, int $status = 302): ResponseInterface
    {
        return $this->factory->createResponse($status)
            ->withHeader('Location', $to)
            ->withHeader(self::ADMIN_HEADER, '1');
    }
}
