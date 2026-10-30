<?php

declare(strict_types=1);

namespace MemoryDown\Web;

use MemoryDown\Auth\TokenStore;
use MemoryDown\Config;
use MemoryDown\Kernel;
use MemoryDown\Memory\MemoryIndex;
use MemoryDown\Memory\MemoryStore;
use MemoryDown\Memory\PathValidator;
use MemoryDown\Memory\SearchEngine;
use MemoryDown\Memory\WorkflowDefinition;
use MemoryDown\Support\ClientIp;
use MemoryDown\Support\RateLimiter;
use MemoryDown\Support\ZipWriter;
use MemoryDown\Users\User;
use MemoryDown\Users\UserDirectories;
use MemoryDown\Users\UserServices;
use MemoryDown\Users\UserServicesFactory;
use MemoryDown\Users\UserStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * The human admin UI: browse, search and edit one account's Markdown memory.
 *
 * Server-rendered shell (works without JS) plus a tiny JSON API under
 * /ui/api/* that the progressive-enhancement JS uses for live search and
 * in-place editing. All storage goes through the memory layer — this class
 * never touches a memory file itself.
 *
 * With accounts there are two kinds of page:
 *
 *   /ui, /ui/trash, /ui/api/*   one account's memory (yours)
 *   /ui/settings                your own password and API tokens
 *   /ui/users                   everyone's accounts (admins only)
 *
 * /ui/users is the only place that looks at more than one account, and it looks
 * at their records — never at their memory. An admin can download somebody
 * else's corpus through /ui/backup?user=<id>, which is a read of the files and
 * nothing more; browsing or editing another account's entries is not offered at
 * all, so the isolation the storage layout provides is not undone by the UI.
 */
final class WebApp
{
    public const ADMIN_HEADER = 'X-MemoryDown-Admin';

    /** An entry is flagged "new" when its Markdown file was modified this recently. */
    private const NEW_WINDOW_SECONDS = 86400;

    /**
     * Flash messages, keyed by a short code passed in the query string. A fixed
     * vocabulary means nothing the user typed — or a link someone else crafted —
     * is ever echoed back into the page.
     */
    private const NOTICES = [
        'restored' => 'Memory restored to its original category.',
        'purged' => 'Trashed entry deleted permanently.',
        'emptied' => 'Trash emptied. Those entries are gone for good.',
        'invalid' => 'That entry is no longer in the trash.',
        'restore_failed' => 'Could not restore that entry: a memory with the same id exists again, or the file could not be moved. Reload and check.',
    ];

    /** The same idea for the account pages. */
    private const ACCOUNT_NOTICES = [
        'password_changed' => 'Password changed. Your other sessions and MCP connections have to sign in again.',
        'user_created' => 'Account created. Give that person their username and password.',
        'user_password' => 'Password reset. That account\'s other sessions and MCP connections have to sign in again.',
        'user_role' => 'Role updated.',
        'user_enabled' => 'Account enabled. It can sign in again, but its old tokens do not come back.',
        'user_disabled' => 'Account disabled. Its sessions and MCP connections stopped working immediately.',
        'user_deleted' => 'Account deleted. Its memory was moved to data/users/.trash and can be brought back over FTP.',
        'token_rotated' => 'New API token generated. Copy it to wherever it is used; the previous one stopped working.',
        'token_cleared' => 'API token cleared. That API no longer serves this account.',
    ];

    private const ACCOUNT_ERRORS = [
        'invalid_id' => 'A username must be 3-32 characters of a-z, 0-9, "_" or "-", and start with a letter or digit.',
        'exists' => 'That username is already taken.',
        'password_short' => 'Choose a password of at least ' . UserStore::MIN_PASSWORD_LENGTH . ' characters.',
        'password_match' => 'The two passwords do not match.',
        'current_password' => 'That is not your current password.',
        'role' => 'Unknown role.',
        'missing' => 'No such account.',
        'last_admin' => 'That would leave the instance without an enabled admin.',
        'self_role' => 'You cannot change your own role.',
        'self_disable' => 'You cannot disable your own account.',
        'self_delete' => 'You cannot delete your own account.',
        'archive_failed' => 'Could not move that account\'s directory aside, so nothing was deleted. Check the permissions on data/users.',
        'invalid' => 'That request was not valid.',
    ];

    /** Shipped inside every backup zip so it explains itself later. */
    private const RESTORE_NOTES = <<<'TXT'
    MemoryDown backup
    =================

    This archive contains every file of one account's memory store under memory/,
    exactly as it sits on disk. The Markdown files are the source of truth;
    nothing here is a database dump and nothing needs importing.

    To restore
    ----------

    1. Unzip this archive anywhere.
    2. Upload the memory/ folder so that its contents land in that account's
       memory directory (by default data/users/<username>/memory), keeping the
       category subfolders.
    3. Done. The search index rebuilds itself from the files on the next search
       (or press Reindex in the admin UI).

    Notes
    -----

    - Only memory/ is included. Restoring it brings back every memory of that one
      account; it does not restore accounts, passwords, API tokens or OAuth
      clients (data/auth), so after a full rebuild you will re-enter those and
      reconnect your MCP clients.
    - index/, sessions/ and logs/ are deliberately absent: the index is disposable
      and per account, the rest is regenerable.
    - The .trash folder inside memory/ holds entries deleted before this backup was
      taken. Restore them from the admin UI if you want them back.
    TXT;

    private Psr17Factory $factory;
    private WebAuth $auth;
    private bool $userResolved = false;
    private ?User $currentUser = null;

    public function __construct(
        private readonly Config $config,
        private readonly UserStore $users,
        private readonly UserServicesFactory $serviceFactory,
        private readonly UserDirectories $directories,
        private readonly TokenStore $tokens,
        private readonly LoggerInterface $logger,
        private readonly ?RateLimiter $limiter = null,
    ) {
        $this->factory = new Psr17Factory();
        $this->auth = new WebAuth($config, $users, $logger);
    }

    public static function fromKernel(): self
    {
        $kernel = Kernel::get();

        return new self(
            $kernel->config,
            $kernel->users,
            $kernel->userServices,
            $kernel->userDirectories,
            $kernel->tokens,
            $kernel->logger,
            $kernel->rateLimiter,
        );
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

                '/ui/settings' === $path && 'GET' === $method => $this->settingsPage($request),
                '/ui/settings/password' === $path && 'POST' === $method => $this->settingsPassword($request),
                '/ui/settings/token' === $path && 'POST' === $method => $this->settingsToken($request),

                '/ui/users' === $path && 'GET' === $method => $this->usersPage($request),
                '/ui/users/create' === $path && 'POST' === $method => $this->userCreate($request),
                '/ui/users/password' === $path && 'POST' === $method => $this->userPassword($request),
                '/ui/users/role' === $path && 'POST' === $method => $this->userRole($request),
                '/ui/users/enabled' === $path && 'POST' === $method => $this->userEnabled($request),
                '/ui/users/token' === $path && 'POST' === $method => $this->userToken($request),
                '/ui/users/delete' === $path && 'POST' === $method => $this->userDelete($request),

                '/ui/reindex' === $path && 'POST' === $method => $this->reindex($request),
                '/ui/backup' === $path && 'GET' === $method => $this->backup($request),
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
            $this->logger->error('admin.unhandled_exception', [
                'message' => $this->redact($e->getMessage()),
                'class' => $e::class,
                'path' => $path,
            ]);

            return $this->json(['error' => 'internal_error'], 500);
        }
    }

    /* ------------------------------------------------------------------ *
     *  Identity
     * ------------------------------------------------------------------ */

    private function user(): ?User
    {
        if (!$this->userResolved) {
            $this->userResolved = true;
            $this->currentUser = $this->auth->currentUser();
        }

        return $this->currentUser;
    }

    /**
     * The memory services of the signed-in account. Every page and API below
     * reaches memory through this, so a request can only ever touch the corpus
     * of the account whose session it carries.
     */
    private function services(): UserServices
    {
        $user = $this->user();
        if (null === $user) {
            throw new \RuntimeException('No signed-in account.');
        }

        return $this->serviceFactory->for($user->id);
    }

    private function store(): MemoryStore
    {
        return $this->services()->memory;
    }

    private function search(): SearchEngine
    {
        return $this->services()->search;
    }

    /** @return ResponseInterface|null null when a session exists */
    private function requireUser(): ?ResponseInterface
    {
        if (null !== $this->user()) {
            return null;
        }

        return $this->auth->isConfigured() ? $this->redirect('/ui/login') : $this->redirect('/ui/setup');
    }

    /** @return ResponseInterface|null null when the session is an admin's */
    private function requireAdmin(): ?ResponseInterface
    {
        $denied = $this->requireUser();
        if (null !== $denied) {
            return $denied;
        }

        return $this->user()?->isAdmin() === true ? null : $this->forbidden();
    }

    /* ------------------------------------------------------------------ *
     *  Pages
     * ------------------------------------------------------------------ */

    private function page(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->auth->isConfigured()) {
            return $this->redirect('/ui/setup');
        }
        if (null !== ($denied = $this->requireUser())) {
            return $denied;
        }

        $query = $request->getQueryParams();
        $selected = null;
        $category = (string) ($query['category'] ?? '');
        $id = (string) ($query['id'] ?? '');
        if (PathValidator::isCategory($category) && PathValidator::isId($id)) {
            $selected = $this->store()->read($category, $id);
        }

        return $this->html($this->view('app', [
            'title' => 'MemoryDown',
            'bodyClass' => 'app',
            'csrf' => $this->auth->csrfToken(),
            'categories' => $this->store()->categories(),
            'tree' => $this->tree(),
            'selected' => $selected,
            'definition' => null === $selected ? null : WorkflowDefinition::activationProblems(
                (string) ($selected['category'] ?? ''),
                $selected['tags'] ?? [],
                (string) ($selected['body'] ?? ''),
            ),
            'trashCount' => $this->store()->trashCount(),
            'engine' => $this->search() instanceof MemoryIndex ? $this->search()->status() : ['engine' => 'direct'],
            'user' => $this->user(),
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
            'username' => '',
            'error' => '',
        ]));
    }

    private function loginSubmit(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->auth->isConfigured()) {
            return $this->redirect('/ui/setup');
        }

        // The session-based lockout in WebAuth is one boundary, but it lives in
        // a cookie an attacker can simply drop; this per-IP limit is the one
        // that cannot be reset by the client.
        if (!$this->withinUiRateLimit('login', $request, $this->config->rateLimitLoginMax)) {
            return $this->html($this->view('login', [
                'title' => 'Sign in',
                'bodyClass' => 'auth',
                'csrf' => $this->auth->csrfToken(),
                'username' => '',
                'error' => 'Too many attempts. Wait a moment and try again.',
            ]), 429)->withHeader('Retry-After', (string) max(1, $this->config->rateLimitWindow));
        }

        $body = $request->getParsedBody() ?? [];
        $username = trim($this->str($body['username'] ?? null));

        if (!$this->auth->checkCsrf($this->str($body['csrf'] ?? null))) {
            return $this->loginError($username, 'Your session expired. Please try again.', 400);
        }

        $error = $this->auth->attempt($username, $this->str($body['password'] ?? null));
        if ('' !== $error) {
            return $this->loginError($username, $error, 401);
        }

        return $this->redirect('/ui');
    }

    private function loginError(string $username, string $error, int $status): ResponseInterface
    {
        return $this->html($this->view('login', [
            'title' => 'Sign in',
            'bodyClass' => 'auth',
            'csrf' => $this->auth->csrfToken(),
            'username' => $username,
            'error' => $error,
        ]), $status);
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
            'username' => $this->suggestedUsername(),
            'error' => '',
        ]));
    }

    private function setupSubmit(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->auth->isConfigured()) {
            return $this->redirect('/ui/login');
        }

        // The setup form is the one moment an instance has no accounts at all
        // and nothing else to protect it; the ADMIN_SETUP_TOKEN is a good
        // secret, but a rate limit means guessing it is slow even when it is
        // weak or unset.
        if (!$this->withinUiRateLimit('setup', $request, $this->config->rateLimitSetupMax)) {
            return $this->html($this->view('setup', [
                'title' => 'Set up MemoryDown',
                'bodyClass' => 'auth',
                'csrf' => $this->auth->csrfToken(),
                'needsToken' => '' !== $this->config->adminSetupToken,
                'username' => $this->suggestedUsername(),
                'error' => 'Too many attempts. Wait a moment and try again.',
            ]), 429)->withHeader('Retry-After', (string) max(1, $this->config->rateLimitWindow));
        }

        $body = $request->getParsedBody() ?? [];
        $error = '';
        $username = strtolower(trim($this->str($body['username'] ?? null)));
        $password = $this->str($body['password'] ?? null);
        $confirm = $this->str($body['confirm'] ?? null);
        $token = $this->str($body['setup_token'] ?? null);

        if (!$this->auth->checkCsrf($this->str($body['csrf'] ?? null))) {
            $error = 'Your session expired. Please try again.';
        } elseif ('' !== $this->config->adminSetupToken && !hash_equals($this->config->adminSetupToken, $token)) {
            $error = 'Invalid setup token.';
        } elseif (!UserStore::isValidId($username)) {
            $error = self::ACCOUNT_ERRORS['invalid_id'];
        } elseif (strlen($password) < UserStore::MIN_PASSWORD_LENGTH) {
            $error = self::ACCOUNT_ERRORS['password_short'];
        } elseif ($password !== $confirm) {
            $error = self::ACCOUNT_ERRORS['password_match'];
        }

        if ('' !== $error) {
            return $this->html($this->view('setup', [
                'title' => 'Set up MemoryDown',
                'bodyClass' => 'auth',
                'csrf' => $this->auth->csrfToken(),
                'needsToken' => '' !== $this->config->adminSetupToken,
                'username' => $username,
                'error' => $error,
            ]), 400);
        }

        try {
            $this->users->create($username, $password, UserStore::ROLE_ADMIN);
        } catch (\InvalidArgumentException $e) {
            return $this->html($this->view('setup', [
                'title' => 'Set up MemoryDown',
                'bodyClass' => 'auth',
                'csrf' => $this->auth->csrfToken(),
                'needsToken' => '' !== $this->config->adminSetupToken,
                'username' => $username,
                'error' => self::ACCOUNT_ERRORS['exists'],
            ]), 400);
        }

        $this->provision($username);
        $this->logger->info('admin.setup.created', ['user' => $username]);
        // Sign the new admin straight in: they just typed the password, and
        // asking for it again on the next page would be theatre.
        $this->auth->attempt($username, $password);

        return $this->redirect('/ui');
    }

    /**
     * The username a first-run or upgraded instance most likely wants: whatever
     * the deprecated OAUTH_USERNAME said, or "admin".
     */
    private function suggestedUsername(): string
    {
        $suggested = strtolower(trim($this->config->oauthUsername));

        return UserStore::isValidId($suggested) ? $suggested : 'admin';
    }

    /* ------------------------------------------------------------------ *
     *  Settings (one's own password and API tokens)
     * ------------------------------------------------------------------ */

    private function settingsPage(ServerRequestInterface $request): ResponseInterface
    {
        if (null !== ($denied = $this->requireUser())) {
            return $denied;
        }
        $user = $this->user();
        \assert(null !== $user);

        return $this->html($this->view('settings', [
            'title' => 'MemoryDown — Settings',
            'bodyClass' => 'settings',
            'csrf' => $this->auth->csrfToken(),
            'user' => $user,
            'tokens' => $this->tokenList($user),
            'apiEnabled' => [
                UserStore::TOKEN_WRITINGS => $this->config->publicApiEnabled,
                UserStore::TOKEN_WORKFLOWS => $this->config->workflowsApiEnabled,
            ],
            'notices' => $this->flash($request, 'notice', self::ACCOUNT_NOTICES),
            'errors' => $this->flash($request, 'error', self::ACCOUNT_ERRORS),
        ]));
    }

    private function settingsPassword(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->user();
        if (null === $user || !$this->guardMutation($request)) {
            return $this->json(['error' => 'forbidden'], 403);
        }

        $data = $this->input($request);
        $current = $this->str($data['current'] ?? null);
        $password = $this->str($data['password'] ?? null);
        $confirm = $this->str($data['confirm'] ?? null);

        if (null === $this->users->verify($user->id, $current)) {
            return $this->settingsError('current_password');
        }
        if (strlen($password) < UserStore::MIN_PASSWORD_LENGTH) {
            return $this->settingsError('password_short');
        }
        if ($password !== $confirm) {
            return $this->settingsError('password_match');
        }

        $this->users->setPassword($user->id, $password);
        // Changing a password bumps the auth epoch, which is how every other
        // session and every MCP token of this account stops working. This
        // session is deliberately carried over to the new epoch: signing
        // yourself out by changing your own password helps nobody.
        $fresh = $this->users->get($user->id);
        if (null !== $fresh) {
            $this->auth->remember($fresh);
            $this->currentUser = $fresh;
        }
        $this->logger->info('admin.password_changed', ['user' => $user->id]);

        return $this->redirect('/ui/settings?notice=password_changed');
    }

    private function settingsToken(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->user();
        if (null === $user || !$this->guardMutation($request)) {
            return $this->json(['error' => 'forbidden'], 403);
        }

        return $this->tokenAction($request, $user->id, '/ui/settings');
    }

    /* ------------------------------------------------------------------ *
     *  Accounts (admins only)
     * ------------------------------------------------------------------ */

    private function usersPage(ServerRequestInterface $request): ResponseInterface
    {
        if (null !== ($denied = $this->requireAdmin())) {
            return $denied;
        }
        $user = $this->user();
        \assert(null !== $user);

        return $this->html($this->view('users', [
            'title' => 'MemoryDown — Users',
            'bodyClass' => 'users',
            'csrf' => $this->auth->csrfToken(),
            'currentId' => $user->id,
            'users' => $this->users->all(),
            'enabledAdmins' => $this->users->enabledAdmins(),
            'tokens' => array_map(fn (User $u): array => $this->tokenList($u), $this->users->all()),
            'notices' => $this->flash($request, 'notice', self::ACCOUNT_NOTICES),
            'errors' => $this->flash($request, 'error', self::ACCOUNT_ERRORS),
        ]));
    }

    private function userCreate(ServerRequestInterface $request): ResponseInterface
    {
        $admin = $this->user();
        if (null === $admin || !$admin->isAdmin() || !$this->guardMutation($request)) {
            return $this->json(['error' => 'forbidden'], 403);
        }

        $data = $this->input($request);
        $id = strtolower(trim($this->str($data['id'] ?? null)));
        $password = $this->str($data['password'] ?? null);
        $confirm = $this->str($data['confirm'] ?? null);
        $role = $this->str($data['role'] ?? null);
        if (!in_array($role, UserStore::ROLES, true)) {
            $role = UserStore::ROLE_USER;
        }

        if (!UserStore::isValidId($id)) {
            return $this->usersError('invalid_id');
        }
        if ($this->users->has($id)) {
            return $this->usersError('exists');
        }
        if (strlen($password) < UserStore::MIN_PASSWORD_LENGTH) {
            return $this->usersError('password_short');
        }
        if ($password !== $confirm) {
            return $this->usersError('password_match');
        }

        try {
            $this->users->create($id, $password, $role);
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('admin.user_create_failed', ['id' => $id, 'message' => $e->getMessage()]);

            return $this->usersError('invalid_id');
        }

        $this->provision($id);
        $this->logger->info('admin.user_created', ['id' => $id, 'role' => $role, 'by' => $admin->id]);

        return $this->redirect('/ui/users?notice=user_created');
    }

    private function userPassword(ServerRequestInterface $request): ResponseInterface
    {
        $admin = $this->user();
        if (null === $admin || !$admin->isAdmin() || !$this->guardMutation($request)) {
            return $this->json(['error' => 'forbidden'], 403);
        }

        $data = $this->input($request);
        $id = strtolower(trim($this->str($data['id'] ?? null)));
        $password = $this->str($data['password'] ?? null);
        $confirm = $this->str($data['confirm'] ?? null);

        if (null === $this->users->get($id)) {
            return $this->usersError('missing');
        }
        if (strlen($password) < UserStore::MIN_PASSWORD_LENGTH) {
            return $this->usersError('password_short');
        }
        if ($password !== $confirm) {
            return $this->usersError('password_match');
        }

        $this->users->setPassword($id, $password);
        // Resetting your own password from this page must not sign you out.
        if ($id === $admin->id) {
            $fresh = $this->users->get($id);
            if (null !== $fresh) {
                $this->auth->remember($fresh);
                $this->currentUser = $fresh;
            }
        }
        $this->logger->info('admin.user_password_reset', ['id' => $id, 'by' => $admin->id]);

        return $this->redirect('/ui/users?notice=user_password');
    }

    private function userRole(ServerRequestInterface $request): ResponseInterface
    {
        $admin = $this->user();
        if (null === $admin || !$admin->isAdmin() || !$this->guardMutation($request)) {
            return $this->json(['error' => 'forbidden'], 403);
        }

        $data = $this->input($request);
        $id = strtolower(trim($this->str($data['id'] ?? null)));
        $role = $this->str($data['role'] ?? null);

        if ($id === $admin->id) {
            return $this->usersError('self_role');
        }
        if (null === $this->users->get($id)) {
            return $this->usersError('missing');
        }
        if (!in_array($role, UserStore::ROLES, true)) {
            return $this->usersError('role');
        }

        try {
            $this->users->setRole($id, $role);
        } catch (\InvalidArgumentException $e) {
            return $this->usersError('last_admin');
        }

        $this->logger->info('admin.user_role', ['id' => $id, 'role' => $role, 'by' => $admin->id]);

        return $this->redirect('/ui/users?notice=user_role');
    }

    private function userEnabled(ServerRequestInterface $request): ResponseInterface
    {
        $admin = $this->user();
        if (null === $admin || !$admin->isAdmin() || !$this->guardMutation($request)) {
            return $this->json(['error' => 'forbidden'], 403);
        }

        $data = $this->input($request);
        $id = strtolower(trim($this->str($data['id'] ?? null)));
        $enabled = filter_var($data['enabled'] ?? false, FILTER_VALIDATE_BOOL);

        if ($id === $admin->id && !$enabled) {
            return $this->usersError('self_disable');
        }
        if (null === $this->users->get($id)) {
            return $this->usersError('missing');
        }

        try {
            $this->users->setEnabled($id, $enabled);
        } catch (\InvalidArgumentException $e) {
            return $this->usersError('last_admin');
        }

        $this->logger->info('admin.user_enabled', ['id' => $id, 'enabled' => $enabled, 'by' => $admin->id]);

        return $this->redirect('/ui/users?notice=' . ($enabled ? 'user_enabled' : 'user_disabled'));
    }

    private function userToken(ServerRequestInterface $request): ResponseInterface
    {
        $admin = $this->user();
        if (null === $admin || !$admin->isAdmin() || !$this->guardMutation($request)) {
            return $this->json(['error' => 'forbidden'], 403);
        }

        $data = $this->input($request);
        $id = strtolower(trim($this->str($data['id'] ?? null)));
        if ($id === $admin->id) {
            // Their own tokens are on /ui/settings; sending them back there
            // keeps one page as the answer to "where is my token".
            return $this->redirect('/ui/settings');
        }
        if (null === $this->users->get($id)) {
            return $this->usersError('missing');
        }

        $location = $this->tokenAction($request, $id, '/ui/users');
        $this->logger->info('admin.user_token', ['id' => $id, 'by' => $admin->id]);

        return $location;
    }

    private function userDelete(ServerRequestInterface $request): ResponseInterface
    {
        $admin = $this->user();
        if (null === $admin || !$admin->isAdmin() || !$this->guardMutation($request)) {
            return $this->json(['error' => 'forbidden'], 403);
        }

        $id = strtolower(trim($this->str($this->input($request)['id'] ?? null)));
        $target = $this->users->get($id);
        if (null === $target) {
            return $this->usersError('missing');
        }
        if ($id === $admin->id) {
            return $this->usersError('self_delete');
        }
        if ($target->isAdmin() && $target->enabled && $this->users->enabledAdmins() <= 1) {
            return $this->usersError('last_admin');
        }

        // The directory goes first: if it cannot be moved, nothing is deleted at
        // all, and the account keeps working with its memory where it was. Only
        // once it is safely aside does the record go, and if that then fails the
        // directory is put back rather than orphaning a corpus.
        $archived = null;
        try {
            $archived = $this->directories->archive($id);
        } catch (\Throwable $e) {
            $this->logger->error('admin.user_archive_failed', ['id' => $id, 'message' => $e->getMessage()]);

            return $this->usersError('archive_failed');
        }

        try {
            $this->users->delete($id);
        } catch (\Throwable $e) {
            if (null !== $archived) {
                $this->directories->restore($archived, $id);
            }
            $this->logger->error('admin.user_delete_failed', ['id' => $id, 'message' => $e->getMessage()]);

            return $this->usersError('last_admin');
        }

        $revoked = $this->tokens->revokeAllForUser($id);
        $this->logger->info('admin.user_deleted', [
            'id' => $id,
            'by' => $admin->id,
            'archived' => $archived,
            'tokens_revoked' => $revoked,
        ]);

        return $this->redirect('/ui/users?notice=user_deleted');
    }

    /* ------------------------------------------------------------------ *
     *  JSON API (one's own memory)
     * ------------------------------------------------------------------ */

    private function apiTree(): ResponseInterface
    {
        if (null !== $this->requireUser()) {
            return $this->json(['error' => 'unauthorized'], 401);
        }

        // `trashed` travels with the tree so the menu badge can be refreshed by
        // the same request the editor already makes after every delete, instead
        // of going stale until the next full page load.
        return $this->json([
            'categories' => $this->tree(),
            'total' => $this->store()->count(),
            'trashed' => $this->store()->trashCount(),
        ]);
    }

    private function apiSearch(ServerRequestInterface $request): ResponseInterface
    {
        if (null !== $this->requireUser()) {
            return $this->json(['error' => 'unauthorized'], 401);
        }

        $query = $request->getQueryParams();
        $q = trim((string) ($query['q'] ?? ''));
        $category = (string) ($query['category'] ?? '');
        $limit = (int) ($query['limit'] ?? 30);
        $tag = trim((string) ($query['tag'] ?? ''));

        if ('' === $q && '' === $tag) {
            return $this->json(['results' => []]);
        }

        $results = $this->search()->search(
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
        if (null !== $this->requireUser()) {
            return $this->json(['error' => 'unauthorized'], 401);
        }

        $query = $request->getQueryParams();
        $category = (string) ($query['category'] ?? '');
        $id = (string) ($query['id'] ?? '');
        if (!PathValidator::isCategory($category) || !PathValidator::isId($id)) {
            return $this->json(['error' => 'invalid_request'], 400);
        }

        $doc = $this->store()->read($category, $id);
        if (null === $doc) {
            return $this->json(['error' => 'not_found'], 404);
        }

        return $this->json([
            'memory' => $doc,
            'definition' => WorkflowDefinition::activationProblems(
                (string) ($doc['category'] ?? ''),
                $doc['tags'] ?? [],
                (string) ($doc['body'] ?? ''),
            ),
        ]);
    }

    private function apiSave(ServerRequestInterface $request): ResponseInterface
    {
        if (null !== $this->requireUser()) {
            return $this->json(['error' => 'unauthorized'], 401);
        }
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

        // A definition stays in `workflows` until its activation is taken away,
        // so moving it out is refused while the tag is still on it. Checked
        // before the rule below, because for a move the actionable message is
        // "remove the tag first", not "that tag belongs elsewhere" — the tag came
        // from the entry being moved, not from the user typing it.
        $currentCategory = '' === $id ? '' : $this->categoryFor($id);
        if ('' !== $currentCategory && $currentCategory !== $category) {
            $current = $this->store()->read($currentCategory, $id);
            if (null !== $current && WorkflowDefinition::hasTag($current['tags'] ?? [])) {
                return $this->json([
                    'error' => 'This entry is an active workflow: remove the "' . WorkflowDefinition::TAG . '" tag before moving it out of "' . WorkflowDefinition::CATEGORY . '".',
                ], 400);
            }
        }

        // The reserved tag is a permission, and it is only meaningful on an entry
        // in `workflows` (docs/WORKFLOWS.md). Anywhere else it would claim an
        // authorisation no runner would honour, so it is refused where it is
        // illegal rather than silently stripped: the user is told what happened
        // instead of losing a definition's activation without noticing.
        $reserved = PathValidator::humanOnlyTag($tags);
        if ('' !== $reserved && !WorkflowDefinition::isWorkflowCategory($category)) {
            return $this->json([
                'error' => 'The "' . $reserved . '" tag can only be used in the "' . WorkflowDefinition::CATEGORY . '" category.',
            ], 400);
        }

        try {
            if ('' === $id) {
                $doc = $this->store()->create($content, $title, $category, $tags, archived: $archived, public: $public);
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
                $doc = $this->store()->update($currentCategory, $id, $changes);
            }
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }

        return $this->json([
            'memory' => $doc,
            'definition' => WorkflowDefinition::activationProblems(
                (string) ($doc['category'] ?? ''),
                $doc['tags'] ?? [],
                (string) ($doc['body'] ?? ''),
            ),
        ]);
    }

    private function apiDelete(ServerRequestInterface $request): ResponseInterface
    {
        if (null !== $this->requireUser()) {
            return $this->json(['error' => 'unauthorized'], 401);
        }
        if (!$this->guardMutation($request)) {
            return $this->json(['error' => 'forbidden'], 403);
        }

        $query = $request->getQueryParams();
        $category = (string) ($query['category'] ?? '');
        $id = (string) ($query['id'] ?? '');
        if (!PathValidator::isCategory($category) || !PathValidator::isId($id)) {
            return $this->json(['error' => 'invalid_request'], 400);
        }

        if (!$this->store()->delete($category, $id)) {
            return $this->json(['error' => 'not_found'], 404);
        }

        return $this->json(['ok' => true, 'id' => $id]);
    }

    private function apiPreview(ServerRequestInterface $request): ResponseInterface
    {
        if (null !== $this->requireUser()) {
            return $this->json(['error' => 'unauthorized'], 401);
        }

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
        if (!$this->auth->isConfigured()) {
            return $this->redirect('/ui/setup');
        }
        if (null !== ($denied = $this->requireUser())) {
            return $denied;
        }

        $query = $request->getQueryParams();
        $entries = $this->store()->trashed();
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
        if (null !== $this->requireUser() || !$this->guardMutation($request)) {
            return $this->json(['error' => 'forbidden'], 403);
        }

        $name = $this->str($this->input($request)['name'] ?? null);
        if (null === $this->store()->paths()->trashFile($name)) {
            return $this->redirect('/ui/trash?error=invalid');
        }

        try {
            $this->store()->restore($name);
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
        if (null !== $this->requireUser() || !$this->guardMutation($request)) {
            return $this->json(['error' => 'forbidden'], 403);
        }

        $name = $this->str($this->input($request)['name'] ?? null);

        return $this->redirect('/ui/trash?' . ($this->store()->purge($name) ? 'notice=purged' : 'error=invalid'));
    }

    private function trashEmpty(ServerRequestInterface $request): ResponseInterface
    {
        if (null !== $this->requireUser() || !$this->guardMutation($request)) {
            return $this->json(['error' => 'forbidden'], 403);
        }

        $this->store()->purgeTrash();

        return $this->redirect('/ui/trash?notice=emptied');
    }

    /**
     * Download one account's Markdown as a zip.
     *
     * Deliberately memory only: no auth state, no account records and no .env,
     * so the archive can never leak a credential — restoring it rebuilds that
     * account's corpus from the canonical files, and the search index rebuilds
     * itself on the next search.
     *
     * An admin may add ?user=<id> to fetch somebody else's corpus — the one
     * power an admin has over another account's data, and a read-only one.
     *
     * A plain GET (no CSRF token): it changes nothing, and although a hostile
     * page could make the browser start this download, it cannot read the
     * response — the bytes land on the user's own disk.
     */
    private function backup(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->auth->isConfigured()) {
            return $this->redirect('/ui/setup');
        }
        $user = $this->user();
        if (null === $user) {
            return $this->redirect('/ui/login');
        }

        $requested = strtolower(trim($this->str($request->getQueryParams()['user'] ?? null)));
        $target = $user;
        if ('' !== $requested && $requested !== $user->id) {
            if (!$user->isAdmin()) {
                return $this->forbidden();
            }
            $other = $this->users->get($requested);
            if (null === $other) {
                return $this->json(['error' => 'not_found'], 404);
            }
            $target = $other;
        }

        $store = $this->serviceFactory->for($target->id)->memory;
        $files = $this->memoryFiles($store);
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
            'account' => $target->id,
            'memories' => $store->count(),
            'files' => count($files),
            'contents' => 'memory/** plus this manifest and RESTORE.txt',
            'excluded' => 'account records and credentials (data/auth), sessions, logs, .env, and every other account',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $zip->add('RESTORE.txt', self::RESTORE_NOTES);

        return $this->download(
            'memorydown-' . $target->id . '-backup-' . gmdate('Y-m-d-Hi') . '.zip',
            'application/zip',
            $zip->finish()
        );
    }

    /**
     * Every file under a memory root, relative path => absolute path. Includes
     * the trash and the root README: a backup should be a faithful copy of the
     * directory, not just the parts the app happens to show.
     *
     * @return array<string, string>
     */
    private function memoryFiles(MemoryStore $store): array
    {
        $root = $store->paths()->root();
        $files = [];

        if (!is_dir($root)) {
            return $files;
        }

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
        if (null !== $this->requireUser() || !$this->guardMutation($request)) {
            return $this->json(['error' => 'forbidden'], 403);
        }

        if ($this->search() instanceof MemoryIndex) {
            $this->search()->rebuild();

            return $this->json(['ok' => true, 'status' => $this->search()->status()]);
        }

        return $this->json(['ok' => true, 'status' => ['engine' => 'direct']]);
    }

    /* ------------------------------------------------------------------ *
     *  Helpers
     * ------------------------------------------------------------------ */

    /**
     * Rotate or clear one API token of $id. Shared by /ui/settings (own tokens)
     * and /ui/users (an admin acting on somebody else's).
     */
    private function tokenAction(ServerRequestInterface $request, string $id, string $back): ResponseInterface
    {
        $data = $this->input($request);
        $kind = $this->str($data['kind'] ?? null);
        $action = $this->str($data['action'] ?? null);

        if (!in_array($kind, UserStore::TOKENS, true)) {
            return $this->redirect($back . '?error=invalid');
        }
        if ('rotate' === $action) {
            $this->users->rotateToken($id, $kind);
            $this->logger->info('admin.token_rotated', ['id' => $id, 'kind' => $kind]);

            return $this->redirect($back . '?notice=token_rotated');
        }
        if ('clear' === $action) {
            $this->users->clearToken($id, $kind);
            $this->logger->info('admin.token_cleared', ['id' => $id, 'kind' => $kind]);

            return $this->redirect($back . '?notice=token_cleared');
        }

        return $this->redirect($back . '?error=invalid');
    }

    /**
     * Create an account's memory root as soon as the account exists.
     *
     * Resolving the services is what provisions the directory tree (categories,
     * trash, README), and doing it here rather than on that person's first
     * request means "the account exists" and "there is a directory to back up,
     * inspect over FTP or archive on delete" are the same statement. It is best
     * effort: if the disk refuses, the account is still valid and the next
     * request retries.
     */
    private function provision(string $id): void
    {
        try {
            $this->serviceFactory->for($id);
        } catch (\Throwable $e) {
            $this->logger->warning('admin.provision_failed', [
                'id' => $id,
                'message' => $this->redact($e->getMessage()),
            ]);
        }
    }

    /**
     * The API tokens of an account, keyed by kind, with a label and whether the
     * corresponding API is switched on at all.
     *
     * @return array<string, array{token: string, enabled: bool}>
     */
    private function tokenList(User $user): array
    {
        return [
            UserStore::TOKEN_WRITINGS => [
                'token' => $user->token(UserStore::TOKEN_WRITINGS),
                'enabled' => $this->config->publicApiEnabled,
            ],
            UserStore::TOKEN_WORKFLOWS => [
                'token' => $user->token(UserStore::TOKEN_WORKFLOWS),
                'enabled' => $this->config->workflowsApiEnabled,
            ],
        ];
    }

    /**
     * @param array<string, string> $vocabulary
     */
    private function flash(ServerRequestInterface $request, string $key, array $vocabulary): string
    {
        $code = $this->str($request->getQueryParams()[$key] ?? null);

        return '' === $code ? '' : ($vocabulary[$code] ?? '');
    }

    private function usersError(string $code): ResponseInterface
    {
        return $this->redirect('/ui/users?error=' . $code);
    }

    private function settingsError(string $code): ResponseInterface
    {
        return $this->redirect('/ui/settings?error=' . $code);
    }

    /**
     * @return list<array{name: string, memories: list<array<string, mixed>>}>
     */
    private function tree(): array
    {
        $categories = $this->store()->categories();
        if ([] === $categories) {
            $categories = PathValidator::DEFAULT_CATEGORIES;
        }

        $grouped = [];
        foreach ($categories as $category) {
            $grouped[$category] = [];
        }

        foreach ($this->store()->list(null, 1000) as $doc) {
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
     * Apply a per-IP fixed-window limit for a UI endpoint (login / setup).
     * Returns true when the request is allowed — including when limiting is
     * disabled or the bucket cannot be written, the same fail-open policy the
     * OAuth endpoints use.
     */
    private function withinUiRateLimit(string $bucket, ServerRequestInterface $request, int $max): bool
    {
        if (null === $this->limiter) {
            return true;
        }

        if ($this->limiter->allow(
            $bucket . ':' . ClientIp::of($request),
            $max,
            $this->config->rateLimitWindow,
        )) {
            return true;
        }

        $this->logger->warning('admin.ui_rate_limited', ['endpoint' => $bucket]);

        return false;
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
        foreach ($this->store()->categories() as $category) {
            if (null !== $this->store()->read($category, $id)) {
                return $category;
            }
        }

        return $this->store()->categories()[0] ?? 'facts';
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

    /**
     * Strip configured secrets out of a message before it reaches the log. An
     * exception can quote anything, including the seed password it was handed.
     */
    private function redact(string $message): string
    {
        foreach ([$this->config->oauthConsentPassword, $this->config->adminSetupToken] as $secret) {
            if ('' !== $secret && strlen($secret) >= 4) {
                $message = str_replace($secret, '[REDACTED]', $message);
            }
        }

        return $message;
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
     * A refusal in HTML rather than JSON, for the pages that are navigated to
     * rather than fetched by script: an admin-only page opened by a plain user
     * should say so, and offer the way back.
     */
    private function forbidden(): ResponseInterface
    {
        return $this->html(
            '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Not allowed</title></head>'
            . '<body style="font-family:system-ui,sans-serif;max-width:36rem;margin:3rem auto;padding:0 1rem">'
            . '<h1>Not allowed</h1><p>That page is for admins.</p><p><a href="/ui">Back to memories</a></p>'
            . '</body></html>',
            403
        );
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
