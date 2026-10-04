<?php

declare(strict_types=1);

/**
 * MemoryDown protocol-chain test suite.
 *
 * Boots a fresh server on an isolated data directory and verifies the whole
 * chain a ChatGPT-style client walks:
 *
 *   health -> protected-resource metadata -> authorization-server metadata
 *   -> 401 + WWW-Authenticate -> DCR -> consent -> code -> token -> refresh
 *   -> MCP initialize (handshake era) -> tools/list -> tool calls
 *   -> MCP server/discover (modern era, 2026-07-28) -> tools/call
 *   -> path traversal attempts
 *
 * Usage:  php tests/run-tests.php [--base-url http://127.0.0.1:PORT] [--keep-server]
 *
 * Exit code 0 = all passed.
 */

const SCOPE = 'memory:all';

$base = 'http://127.0.0.1:8123';
$keepServer = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--base-url=')) {
        $base = rtrim(substr($arg, 11), '/');
    }
    if ('--keep-server' === $arg) {
        $keepServer = true;
    }
}

/* ------------------------------------------------------------------ *
 *  Environment
 * ------------------------------------------------------------------ */

$tmp = __DIR__ . '/tmp-run';
@exec((PHP_OS_FAMILY === 'Windows' ? 'rmdir /s /q ' : 'rm -rf ') . escapeshellarg($tmp));
mkdir($tmp . '/memory', 0775, true);
mkdir($tmp . '/logs', 0775, true);

$phpBin = PHP_BINARY;
$host = parse_url($base, PHP_URL_HOST) ?: '127.0.0.1';
$port = parse_url($base, PHP_URL_PORT) ?: 8123;

$serverProc = null;
if (!$keepServer) {
    putenv('APP_BASE_URL=' . $base);
    putenv('APP_ENV=testing');
    putenv('DATA_PATH=' . $tmp);
    putenv('MEMORY_PATH=' . $tmp . '/memory');
    putenv('LOG_PATH=' . $tmp . '/logs');
    putenv('OAUTH_USERNAME=test-user');
    putenv('OAUTH_CONSENT_PASSWORD=' . password_hash('test-password', PASSWORD_DEFAULT));
    putenv('ADMIN_PASSWORD_HASH=' . password_hash('secret123', PASSWORD_DEFAULT));

    // The child `php -S` server inherits the current PHP configuration (its
    // php.ini / loaded extensions). We deliberately do NOT add `-d extension=`
    // flags: on some setups `dirname(PHP_BINARY)/ext` exists but is not the
    // real extension_dir, which would break the child process. Provide the
    // required extensions via php.ini instead (the CI setup does this).
    $cmd = array_merge([$phpBin], ['-S', $host . ':' . $port, '-t', dirname(__DIR__) . '/public_html', dirname(__DIR__) . '/public_html/index.php']);
    // Redirect server output to files (pipes would deadlock once php -S fills the stderr buffer).
    $serverProc = proc_open($cmd, [
        1 => ['file', $tmp . '/server.out.log', 'w'],
        2 => ['file', $tmp . '/server.err.log', 'w'],
    ], $pipes, dirname(__DIR__));
    waitForServer($base);
}

/* ------------------------------------------------------------------ *
 *  Test harness
 * ------------------------------------------------------------------ */

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

/**
 * @param array<string, string> $headers
 *
 * @return array{status: int, headers: array<string, string>, body: string}
 */
/** @var array<string, string> session cookie jar shared by all requests */
$GLOBALS['memorydown_cookies'] = [];

function request(string $method, string $path, array $headers = [], ?string $body = null): array
{
    global $base;
    $headerLines = [];
    foreach ($headers as $k => $v) {
        $headerLines[] = "{$k}: {$v}";
    }
    if ([] !== $GLOBALS['memorydown_cookies']) {
        $pairs = [];
        foreach ($GLOBALS['memorydown_cookies'] as $name => $value) {
            $pairs[] = "{$name}={$value}";
        }
        $headerLines[] = 'Cookie: ' . implode('; ', $pairs);
    }
    $ctx = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headerLines) . "\r\n",
        'content' => $body,
        'ignore_errors' => true,
        'timeout' => 30,
        'follow_location' => 0,
    ]]);
    $raw = file_get_contents($base . $path, false, $ctx);
    $parsedHeaders = [];
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
            $status = (int) $m[1];
        } elseif (preg_match('/^set-cookie:\s*(.*)$/i', $line, $m)) {
            $pair = trim(explode(';', $m[1])[0]);
            if (str_contains($pair, '=')) {
                [$name, $value] = explode('=', $pair, 2);
                $GLOBALS['memorydown_cookies'][trim($name)] = trim($value);
            }
        } elseif (preg_match('/^([^:]+):\s*(.*)$/', $line, $m)) {
            $parsedHeaders[strtolower($m[1])] = $m[2];
        }
    }

    return ['status' => $status, 'headers' => $parsedHeaders, 'body' => (string) $raw];
}

function jsonBody(string $body): array
{
    return json_decode($body, true) ?? [];
}

function waitForServer(string $base, int $attempts = 40): void
{
    for ($i = 0; $i < $attempts; ++$i) {
        $ctx = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
        if (@file_get_contents($base . '/health', false, $ctx) !== false) {
            return;
        }
        usleep(250000);
    }

    // Surface the server's own output so a failed start is diagnosable instead
    // of cascading into dozens of misleading assertion failures.
    $log = __DIR__ . '/tmp-run/server.err.log';
    fwrite(STDERR, "Server did not come up at {$base}\n");
    if (is_file($log)) {
        fwrite(STDERR, "--- server.err.log ---\n" . (string) file_get_contents($log) . "\n");
    }
    exit(2);
}

function b64url(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function pkcePair(): array
{
    $verifier = b64url(random_bytes(48));

    return [$verifier, b64url(hash('sha256', $verifier, true))];
}

function toolResult(array $json): array
{
    return $json['result']['structuredContent'] ?? $json['result'] ?? [];
}

/* ------------------------------------------------------------------ *
 *  1. Health and diagnostics
 * ------------------------------------------------------------------ */

section('Health & diagnostics');

$r = request('GET', '/health');
check('GET /health -> 200', 200 === $r['status']);
check('GET /health -> status ok', 'ok' === (jsonBody($r['body'])['status'] ?? null));
check('GET /health -> memory path writable', true === (jsonBody($r['body'])['checks']['memory_writable'] ?? false));

$r = request('GET', '/health/mcp');
$mcp = jsonBody($r['body']);
check('GET /health/mcp -> 200', 200 === $r['status']);
check('GET /health/mcp -> 6 tools', 6 === count($mcp['tools'] ?? []));
check('GET /health/mcp -> both protocol eras', isset($mcp['protocol_eras']['handshake'], $mcp['protocol_eras']['modern']));

$r = request('GET', '/health/oauth');
$oauth = jsonBody($r['body']);
check('GET /health/oauth -> 200', 200 === $r['status']);
check('GET /health/oauth -> S256 advertised', in_array('S256', $oauth['supported']['pkce_methods'] ?? [], true));

$r = request('GET', '/');
check('GET / -> landing page (human)', 200 === $r['status'] && str_contains($r['body'], 'MemoryDown'));
check('GET / -> security headers', 'DENY' === ($r['headers']['x-frame-options'] ?? '') && 'nosniff' === ($r['headers']['x-content-type-options'] ?? ''));

$r = request('GET', '/health');
check('GET /health -> security headers', 'nosniff' === ($r['headers']['x-content-type-options'] ?? ''));

/* ------------------------------------------------------------------ *
 *  2. OAuth discovery documents
 * ------------------------------------------------------------------ */

section('OAuth discovery');

$r = request('GET', '/.well-known/oauth-protected-resource');
$rs = jsonBody($r['body']);
check('GET /.well-known/oauth-protected-resource -> 200', 200 === $r['status']);
check('RS metadata: resource == base URL', ($rs['resource'] ?? '') === $base);
check('RS metadata: authorization_servers present', ($rs['authorization_servers'][0] ?? '') === $base);
check('RS metadata: scopes_supported', in_array(SCOPE, $rs['scopes_supported'] ?? [], true));

$r = request('GET', '/.well-known/oauth-protected-resource/mcp');
$rsMcp = jsonBody($r['body']);
check('RS metadata at /mcp path: resource == base/mcp', ($rsMcp['resource'] ?? '') === $base . '/mcp');

$r = request('GET', '/.well-known/oauth-authorization-server');
$as = jsonBody($r['body']);
check('GET /.well-known/oauth-authorization-server -> 200', 200 === $r['status']);
check('AS metadata: issuer', ($as['issuer'] ?? '') === $base);
check('AS metadata: authorization_endpoint', ($as['authorization_endpoint'] ?? '') === $base . '/oauth/authorize');
check('AS metadata: token_endpoint', ($as['token_endpoint'] ?? '') === $base . '/oauth/token');
check('AS metadata: code_challenge_methods_supported includes S256', in_array('S256', $as['code_challenge_methods_supported'] ?? [], true));
check('AS metadata: token_endpoint_auth_methods_supported includes none', in_array('none', $as['token_endpoint_auth_methods_supported'] ?? [], true));
check('AS metadata: CIMD not advertised (DCR primary, like Calendar)', !array_key_exists('client_id_metadata_document_supported', $as));
check('AS metadata: registration_endpoint present', isset($as['registration_endpoint']));
check('AS metadata: iss parameter supported (RFC 9207)', true === ($as['authorization_response_iss_parameter_supported'] ?? false));

$r = request('GET', '/.well-known/openid-configuration');
check('GET /.well-known/openid-configuration -> 200', 200 === $r['status']);

/* ------------------------------------------------------------------ *
 *  3. Unauthenticated MCP request -> 401 challenge
 * ------------------------------------------------------------------ */

section('401 challenge');

$body = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"test","version":"1.0.0"}}}';
$r = request('POST', '/mcp', [
    'Content-Type' => 'application/json',
    'Accept' => 'application/json, text/event-stream',
], $body);
$challenge = $r['headers']['www-authenticate'] ?? '';
check('POST /mcp without token -> 401', 401 === $r['status'], (string) $r['status']);
check('401: WWW-Authenticate Bearer challenge', str_starts_with($challenge, 'Bearer '), $challenge);
check('401: resource_metadata for /mcp path', str_contains($challenge, $base . '/.well-known/oauth-protected-resource/mcp'), $challenge);
check('401: scope present', str_contains($challenge, 'scope="' . SCOPE . '"'), $challenge);

$r = request('POST', '/', [
    'Content-Type' => 'application/json',
    'Accept' => 'application/json, text/event-stream',
], $body);
$challengeRoot = $r['headers']['www-authenticate'] ?? '';
check('POST / without token -> 401', 401 === $r['status']);
check('401 at base URL: resource_metadata for root', str_contains($challengeRoot, $base . '/.well-known/oauth-protected-resource"'), $challengeRoot);

// Regression: ChatGPT's connector may send an Origin header. It must not be
// rejected with 403 by Origin/Host allow-listing; the OAuth challenge (401)
// is the correct response.
$r = request('POST', '/mcp', [
    'Content-Type' => 'application/json',
    'Accept' => 'application/json, text/event-stream',
    'Origin' => 'https://chatgpt.com',
], $body);
check('POST /mcp with Origin header -> 401 (not 403)', 401 === $r['status'], (string) $r['status']);

/* ------------------------------------------------------------------ *
 *  4. Client registration (DCR) and authorization
 * ------------------------------------------------------------------ */

section('OAuth: registration & consent');

$redirectUri = $base . '/callback';
$registerPayload = json_encode([
    'client_name' => 'Test MCP Client',
    'redirect_uris' => [$redirectUri],
    'grant_types' => ['authorization_code', 'refresh_token'],
    'response_types' => ['code'],
    'token_endpoint_auth_method' => 'none',
]);

$r = request('POST', '/oauth/register', ['Content-Type' => 'application/json'], $registerPayload);
$client = jsonBody($r['body']);
check('POST /oauth/register -> 201', 201 === $r['status']);
check('DCR: client_id issued', str_starts_with($client['client_id'] ?? '', 'dcr_'));
check('DCR: redirect_uris echoed', ($client['redirect_uris'][0] ?? '') === $redirectUri);
$clientId = $client['client_id'];

$r = request('POST', '/oauth/register', ['Content-Type' => 'text/plain'], $registerPayload);
check('DCR: non-JSON Content-Type rejected', 400 === $r['status'], (string) $r['status']);

$r = request('POST', '/oauth/register', ['Content-Type' => 'application/json'], json_encode([
    'client_name' => 'Bad Client',
    'redirect_uris' => ['https://evil.example.com/cb'],
    'token_endpoint_auth_method' => 'client_secret_post',
]));
check('DCR: rejects confidential clients', 400 === $r['status']);

$r = request('POST', '/oauth/register', ['Content-Type' => 'application/json'], json_encode([
    'client_name' => 'Bad Client 2',
    'redirect_uris' => ['http://evil.example.com/cb'],
]));
check('DCR: rejects non-loopback http redirect', 400 === $r['status']);

[$verifier, $challenge] = pkcePair();
$authQuery = http_build_query([
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'scope' => SCOPE,
    'state' => 'state-123',
    'code_challenge' => $challenge,
    'code_challenge_method' => 'S256',
    'resource' => $base . '/mcp',
]);

$r = request('GET', '/oauth/authorize?' . $authQuery);
check('GET /oauth/authorize -> consent page', 200 === $r['status'] && str_contains($r['body'], 'Test MCP Client'), (string) $r['status']);

$r = request('GET', '/oauth/authorize?' . http_build_query([
    'client_id' => $clientId,
    'redirect_uri' => 'https://evil.example.com/cb',
    'response_type' => 'code',
]));
check('authorize: mismatched redirect_uri rejected', 400 === $r['status']);

$r = request('POST', '/oauth/authorize?' . $authQuery, ['Content-Type' => 'application/x-www-form-urlencoded'],
    http_build_query(['decision' => 'allow', 'username' => 'test-user', 'consent_password' => 'wrong-password']));
check('authorize: wrong consent password denied', 200 === $r['status'] && str_contains($r['body'], 'Invalid username or password'));

$r = request('POST', '/oauth/authorize?' . $authQuery, ['Content-Type' => 'application/x-www-form-urlencoded'],
    http_build_query(['decision' => 'allow', 'username' => 'wrong-user', 'consent_password' => 'test-password']));
check('authorize: wrong username denied', 200 === $r['status'] && str_contains($r['body'], 'Invalid username or password'));

$r = request('POST', '/oauth/authorize?' . $authQuery, ['Content-Type' => 'application/x-www-form-urlencoded'],
    http_build_query(['decision' => 'allow', 'username' => 'test-user', 'consent_password' => 'test-password']));
$location = $r['headers']['location'] ?? '';
check('authorize: approved -> 302 redirect', 302 === $r['status'], $location);
check('authorize: code in redirect', str_contains($location, 'code='));
check('authorize: state echoed', str_contains($location, 'state=state-123'));
check('authorize: iss present (RFC 9207)', str_contains($location, 'iss=' . rawurlencode($base)));
parse_str((string) parse_url($location, PHP_URL_QUERY), $callbackParams);
$code = $callbackParams['code'] ?? '';

$r = request('POST', '/oauth/authorize?' . $authQuery, ['Content-Type' => 'application/x-www-form-urlencoded'],
    http_build_query(['decision' => 'deny']));
$denyLocation = $r['headers']['location'] ?? '';
check('authorize: deny -> error=access_denied', 302 === $r['status'] && str_contains($denyLocation, 'error=access_denied'));

/* ------------------------------------------------------------------ *
 *  5. Token endpoint
 * ------------------------------------------------------------------ */

section('OAuth: token endpoint');

$r = request('POST', '/oauth/token', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
    'grant_type' => 'authorization_code',
    'code' => $code,
    'code_verifier' => $verifier,
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'resource' => $base . '/mcp',
]));
$token = jsonBody($r['body']);
check('token: authorization_code -> 200', 200 === $r['status'], $r['body']);
check('token: access_token issued', isset($token['access_token']) && is_string($token['access_token']) && '' !== $token['access_token']);
check('token: token_type Bearer', 'Bearer' === ($token['token_type'] ?? ''));
check('token: refresh_token issued', isset($token['refresh_token']));
check('token: scope echoed', SCOPE === ($token['scope'] ?? ''));
check('token: resource echoed', ($token['resource'] ?? '') === $base . '/mcp', $token['resource'] ?? '');
$accessToken = $token['access_token'] ?? '';
$refreshToken = $token['refresh_token'] ?? '';

$r = request('POST', '/oauth/token', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
    'grant_type' => 'authorization_code',
    'code' => $code,
    'code_verifier' => $verifier,
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
]));
check('token: code single-use (replay rejected)', 400 === $r['status']);

[$badVerifier, ] = pkcePair();
$r = request('POST', '/oauth/token', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
    'grant_type' => 'authorization_code',
    'code' => 'not-a-real-code',
    'code_verifier' => $badVerifier,
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
]));
check('token: fake code rejected', 400 === $r['status'] && 'invalid_grant' === (jsonBody($r['body'])['error'] ?? ''));

$r = request('POST', '/oauth/token', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
    'grant_type' => 'refresh_token',
    'refresh_token' => $refreshToken,
    'client_id' => $clientId,
]));
$rotated = jsonBody($r['body']);
check('token: refresh rotation -> 200', 200 === $r['status']);
check('token: rotated refresh token differs', ($rotated['refresh_token'] ?? '') !== '' && ($rotated['refresh_token'] ?? '') !== $refreshToken);
$newAccess = $rotated['access_token'] ?? '';
$newRefresh = $rotated['refresh_token'] ?? '';

$r = request('POST', '/oauth/token', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
    'grant_type' => 'refresh_token',
    'refresh_token' => $refreshToken,
    'client_id' => $clientId,
]));
check('token: old refresh token rejected after rotation', 400 === $r['status']);

/* ------------------------------------------------------------------ *
 *  6. MCP handshake era (initialize / tools/list / tools/call)
 * ------------------------------------------------------------------ */

section('MCP handshake era (2025-11-25)');

$auth = ['Authorization' => 'Bearer ' . $newAccess, 'Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'];

$r = request('POST', '/mcp', $auth, $body);
$init = jsonBody($r['body']);
check('initialize -> 200', 200 === $r['status'], $r['body']);
check('initialize -> protocolVersion negotiated', '2025-11-25' === ($init['result']['protocolVersion'] ?? null), $r['body']);
$sessionId = $r['headers']['mcp-session-id'] ?? '';
check('initialize -> MCP-Session-Id issued', '' !== $sessionId);

$session = $auth + ['Mcp-Session-Id' => $sessionId];

$r = request('POST', '/mcp', $session + ['Accept' => 'application/json'], '{"jsonrpc":"2.0","method":"notifications/initialized","params":{}}');
check('notifications/initialized -> 202', 202 === $r['status']);

$r = request('POST', '/mcp', $session, '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}');
$tools = jsonBody($r['body']);
$toolNames = array_map(static fn (array $t): string => $t['name'], $tools['result']['tools'] ?? []);
check('tools/list -> 6 tools', 6 === count($toolNames ?? []), json_encode($toolNames));
foreach (['remember', 'recall', 'search_memory', 'update_memory', 'forget_memory', 'list_memory'] as $expected) {
    check("tools/list includes {$expected}", in_array($expected, $toolNames, true));
}

$r = request('POST', '/mcp', $session, json_encode([
    'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
    'params' => ['name' => 'remember', 'arguments' => [
        'content' => 'The user prefers concise answers with concrete examples.',
        'title' => 'Answer style preference',
        'category' => 'preferences',
        'tags' => ['answers', 'style'],
    ]],
]));
$res = jsonBody($r['body']);
check('tools/call remember -> ok', false === ($res['result']['isError'] ?? true), $r['body']);
$remembered = toolResult($res);
check('remember -> created', 'created' === ($remembered['action'] ?? ''), json_encode($remembered));
$memId = $remembered['memory']['id'] ?? '';
check('remember -> id returned', '' !== $memId);
$mdFile = $tmp . '/memory/preferences/' . $memId . '.md';
check('remember -> Markdown file exists', is_file($mdFile), $mdFile);
$mdRaw = is_file($mdFile) ? (string) file_get_contents($mdFile) : '';
check('remember -> frontmatter written', str_starts_with($mdRaw, "---\n"));
check('remember -> body written', str_contains($mdRaw, 'concise answers'));
check('remember -> tags written to frontmatter', str_contains($mdRaw, 'answers') && str_contains($mdRaw, 'style'), $mdRaw);
check('remember -> tags returned in result', ['answers', 'style'] === ($remembered['memory']['tags'] ?? []), json_encode($remembered['memory']['tags'] ?? null));

$r = request('POST', '/mcp', $session, json_encode([
    'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call',
    'params' => ['name' => 'remember', 'arguments' => [
        'content' => 'The user prefers concise answers with concrete examples.',
        'title' => 'Answer style preference',
        'category' => 'preferences',
    ]],
]));
$dup = jsonBody($r['body']);
check('remember -> duplicate deduped to update', 'updated' === (toolResult($dup)['action'] ?? ''), $r['body']);

$r = request('POST', '/mcp', $session, json_encode([
    'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call',
    'params' => ['name' => 'search_memory', 'arguments' => ['query' => 'concise answers']],
]));
$search = toolResult(jsonBody($r['body']));
check('search_memory -> finds the memory', ($search['results'][0]['id'] ?? '') === $memId, json_encode($search));

$r = request('POST', '/mcp', $session, json_encode([
    'jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call',
    'params' => ['name' => 'recall', 'arguments' => ['query' => 'answer style']],
]));
$recall = toolResult(jsonBody($r['body']));
check('recall -> returns the memory', 1 === count($recall['memories'] ?? []), json_encode($recall));

$r = request('POST', '/mcp', $session, json_encode([
    'jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call',
    'params' => ['name' => 'list_memory', 'arguments' => ['category' => 'preferences']],
]));
$list = toolResult(jsonBody($r['body']));
check('list_memory -> lists the entry', in_array($memId, array_column($list['memories'] ?? [], 'id'), true));

$r = request('POST', '/mcp', $session, json_encode([
    'jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call',
    'params' => ['name' => 'update_memory', 'arguments' => ['id' => $memId, 'content' => 'The user prefers short answers with examples and a summary line.']],
]));
$upd = jsonBody($r['body']);
check('update_memory -> ok', false === ($upd['result']['isError'] ?? true), $r['body']);
check('update_memory -> content changed', str_contains($upd['result']['structuredContent']['memory']['body'] ?? '', 'summary line'), $r['body']);

$r = request('POST', '/mcp', $session, json_encode([
    'jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call',
    'params' => ['name' => 'forget_memory', 'arguments' => ['id' => $memId, 'category' => 'preferences']],
]));
$del = jsonBody($r['body']);
check('forget_memory -> ok', false === ($del['result']['isError'] ?? true), $r['body']);
clearstatcache(true, $mdFile);
check('forget_memory -> file deleted', !is_file($mdFile));

/* ------------------------------------------------------------------ *
 *  7. MCP modern era (2026-07-28, stateless)
 * ------------------------------------------------------------------ */

section('MCP modern era (2026-07-28)');

$modernHeaders = $auth + [
    'Content-Type' => 'application/json',
    'Accept' => 'application/json, text/event-stream',
    'MCP-Protocol-Version' => '2026-07-28',
    'Mcp-Method' => 'server/discover',
];
$modernMeta = [
    'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
    'io.modelcontextprotocol/clientCapabilities' => new stdClass(),
];
$r = request('POST', '/mcp', $modernHeaders, json_encode([
    'jsonrpc' => '2.0', 'id' => 10, 'method' => 'server/discover',
    'params' => ['_meta' => $modernMeta],
]));
$discover = jsonBody($r['body']);
check('server/discover -> 200', 200 === $r['status'], $r['body']);
check('server/discover -> serverInfo present', isset($discover['result']['_meta']['io.modelcontextprotocol/serverInfo']['name']), $r['body']);

$r = request('POST', '/mcp', array_replace($modernHeaders, ['Mcp-Method' => 'tools/list']), json_encode([
    'jsonrpc' => '2.0', 'id' => 11, 'method' => 'tools/list',
    'params' => ['_meta' => $modernMeta],
]));
$modernTools = jsonBody($r['body']);
check('modern tools/list -> 6 tools', 6 === count($modernTools['result']['tools'] ?? []), $r['body']);

$r = request('POST', '/mcp', array_replace($modernHeaders, ['Mcp-Method' => 'tools/call', 'Mcp-Name' => 'remember']), json_encode([
    'jsonrpc' => '2.0', 'id' => 12, 'method' => 'tools/call',
    'params' => ['name' => 'remember', 'arguments' => [
        'content' => 'Deployment target is cheap shared hosting.',
        'title' => 'Deployment preference',
        'category' => 'context',
    ], '_meta' => $modernMeta],
]));
$modernRes = jsonBody($r['body']);
check('modern tools/call remember -> ok', false === ($modernRes['result']['isError'] ?? true), $r['body']);
$modernId = toolResult($modernRes)['memory']['id'] ?? '';
check('modern remember -> file created', is_file($tmp . '/memory/context/' . $modernId . '.md'));

$r = request('POST', '/mcp', array_replace($modernHeaders, ['Mcp-Method' => 'tools/call', 'Mcp-Name' => 'search_memory']), json_encode([
    'jsonrpc' => '2.0', 'id' => 13, 'method' => 'tools/call',
    'params' => ['name' => 'search_memory', 'arguments' => ['query' => 'shared hosting'], '_meta' => $modernMeta],
]));
check('modern tools/call search -> found', ($modernSearchId = (toolResult(jsonBody($r['body']))['results'][0]['id'] ?? '')) === $modernId, $r['body']);

/* ------------------------------------------------------------------ *
 *  8. Security: traversal & invalid input
 * ------------------------------------------------------------------ */

section('Security');

$r = request('POST', '/mcp', $session, json_encode([
    'jsonrpc' => '2.0', 'id' => 14, 'method' => 'tools/call',
    'params' => ['name' => 'remember', 'arguments' => ['content' => 'evil', 'title' => 'x', 'category' => '../../outside']],
]));
$evil = jsonBody($r['body']);
check('remember: traversal category rejected', true === ($evil['result']['isError'] ?? false), $r['body']);
check('remember: traversal error message', str_contains($evil['result']['content'][0]['text'] ?? '', 'Invalid category'), $r['body']);

$r = request('POST', '/mcp', $session, json_encode([
    'jsonrpc' => '2.0', 'id' => 15, 'method' => 'tools/call',
    'params' => ['name' => 'update_memory', 'arguments' => ['id' => '../secret', 'content' => 'x']],
]));
check('update_memory: traversal id rejected', true === ((jsonBody($r['body']))['result']['isError'] ?? false));

$r = request('POST', '/mcp', $session, json_encode([
    'jsonrpc' => '2.0', 'id' => 16, 'method' => 'tools/call',
    'params' => ['name' => 'forget_memory', 'arguments' => ['id' => '..%2f..%2findex']],
]));
check('forget_memory: encoded traversal rejected', true === ((jsonBody($r['body']))['result']['isError'] ?? false));

$r = request('POST', '/mcp', $session, json_encode([
    'jsonrpc' => '2.0', 'id' => 17, 'method' => 'tools/call',
    'params' => ['name' => 'forget_memory', 'arguments' => ['id' => 'nonexistent-000000']],
]));
check('forget_memory: unknown id -> tool error', true === ((jsonBody($r['body']))['result']['isError'] ?? false));

/* ------------------------------------------------------------------ *
 *  9. Admin UI: password, session, CRUD, preview, search index
 * ------------------------------------------------------------------ */

section('Admin UI (session, CRUD, preview, index)');

$r = request('GET', '/ui');
check('GET /ui unauthenticated -> 302 to login', 302 === $r['status'] && str_contains($r['headers']['location'] ?? '', '/ui/login'), (string) $r['status']);

$r = request('GET', '/ui/login');
check('GET /ui/login -> 200', 200 === $r['status']);
preg_match('/name="csrf" value="([^"]+)"/', $r['body'], $m);
$adminCsrf = $m[1] ?? '';
check('admin login page exposes csrf token', '' !== $adminCsrf);

$r = request('POST', '/ui/login', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['csrf' => $adminCsrf, 'password' => 'wrong-password']));
check('admin login wrong password -> 401', 401 === $r['status'], (string) $r['status']);

$r = request('POST', '/ui/login', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['password' => 'secret123']));
check('admin login missing csrf -> 400', 400 === $r['status'], (string) $r['status']);

$r = request('POST', '/ui/login', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['csrf' => $adminCsrf, 'password' => 'secret123']));
check('admin login -> 302 to /ui', 302 === $r['status'] && str_contains($r['headers']['location'] ?? '', '/ui'), (string) $r['status']);

$r = request('GET', '/ui');
check('GET /ui authenticated -> 200 shell', 200 === $r['status'] && str_contains($r['body'], 'app-shell'), (string) $r['status']);
check('serial: mobile drawers present', str_contains($r['body'], 'id="open-memories"') && str_contains($r['body'], 'id="open-menu"') && str_contains($r['body'], 'id="command-drawer"') && str_contains($r['body'], 'id="backdrop"'), (string) $r['status']);
check('admin CSP allows self-hosted scripts', str_contains($r['headers']['content-security-policy'] ?? '', "script-src 'self'"));
preg_match('/data-csrf="([^"]+)"/', $r['body'], $m);
$apiCsrf = $m[1] ?? '';
check('admin shell exposes csrf token', '' !== $apiCsrf);

$r = request('POST', '/ui/api/memory', ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf], json_encode([
    'category' => 'preferences',
    'title' => 'Editor preference',
    'tags' => ['ui', 'markdown'],
    'content' => 'The user prefers a **two-pane** editor with live preview.',
]));
$created = jsonBody($r['body']);
$adminId = $created['memory']['id'] ?? '';
check('admin API create -> 200 + id', 200 === $r['status'] && '' !== $adminId, $r['body']);
$adminFile = $tmp . '/memory/preferences/' . $adminId . '.md';
check('admin API create -> Markdown file written', is_file($adminFile), $adminFile);

$r = request('POST', '/ui/api/memory', ['Content-Type' => 'application/json'], json_encode(['category' => 'facts', 'content' => 'no csrf']));
check('admin API create without csrf -> 403', 403 === $r['status'], (string) $r['status']);

$r = request('GET', '/ui/api/tree');
check('admin API tree -> lists new memory', str_contains($r['body'], $adminId));

$tree = jsonBody($r['body']);
$treeNew = null;
foreach (($tree['categories'] ?? []) as $group) {
    foreach (($group['memories'] ?? []) as $mem) {
        if (($mem['id'] ?? '') === $adminId) { $treeNew = $mem['new'] ?? null; }
    }
}
check('admin API tree -> fresh memory flagged new', true === $treeNew, $r['body']);

$r = request('GET', '/ui/api/search?q=two-pane');
check('admin API search -> finds new memory', str_contains($r['body'], $adminId), $r['body']);

$searchHit = null;
foreach ((jsonBody($r['body'])['results'] ?? []) as $res) {
    if (($res['id'] ?? '') === $adminId) { $searchHit = $res['new'] ?? null; }
}
check('admin API search -> fresh memory flagged new', true === $searchHit, $r['body']);

// An entry older than the 24h window must not be flagged new.
@touch($adminFile, time() - 3 * 86400);
$r = request('GET', '/ui/api/tree');
$oldFlag = null;
foreach ((jsonBody($r['body'])['categories'] ?? []) as $group) {
    foreach (($group['memories'] ?? []) as $mem) {
        if (($mem['id'] ?? '') === $adminId) { $oldFlag = $mem['new'] ?? null; }
    }
}
check('admin API tree -> memory older than 24h not new', false === $oldFlag, $r['body']);

// New entries sort first within a category, and archived entries are never "new".
$r = request('POST', '/ui/api/memory', ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf], json_encode([
    'category' => 'notes', 'title' => 'Sort fresh', 'content' => 'fresh sort probe',
]));
$sortFreshId = jsonBody($r['body'])['memory']['id'] ?? '';
$r = request('POST', '/ui/api/memory', ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf], json_encode([
    'category' => 'notes', 'title' => 'Sort old', 'content' => 'old sort probe',
]));
$sortOldId = jsonBody($r['body'])['memory']['id'] ?? '';
@touch($tmp . '/memory/notes/' . $sortOldId . '.md', time() - 3 * 86400);

$r = request('GET', '/ui/api/tree');
$notesOrder = [];
foreach ((jsonBody($r['body'])['categories'] ?? []) as $group) {
    if (($group['name'] ?? '') === 'notes') {
        foreach (($group['memories'] ?? []) as $mem) { $notesOrder[] = $mem['id'] ?? ''; }
    }
}
$freshPos = array_search($sortFreshId, $notesOrder, true);
$oldPos = array_search($sortOldId, $notesOrder, true);
check('tree: new entry sorted before old', false !== $freshPos && false !== $oldPos && $freshPos < $oldPos, json_encode($notesOrder));

// Archiving a fresh entry must clear its "new" flag.
$r = request('POST', '/ui/api/memory', ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf], json_encode([
    'id' => $sortFreshId, 'category' => 'notes', 'title' => 'Sort fresh', 'tags' => '', 'content' => 'fresh sort probe', 'archived' => true,
]));
check('admin API archive fresh -> ok', 200 === $r['status'], $r['body']);
$r = request('GET', '/ui/api/tree');
$archNew = null;
foreach ((jsonBody($r['body'])['categories'] ?? []) as $group) {
    foreach (($group['memories'] ?? []) as $mem) {
        if (($mem['id'] ?? '') === $sortFreshId) { $archNew = $mem['new'] ?? null; }
    }
}
check('tree: archived entry never flagged new', false === $archNew, $r['body']);

$r = request('GET', '/ui/api/memory?category=preferences&id=' . rawurlencode($adminId));
check('admin API get -> returns body', str_contains($r['body'], 'two-pane'));
check('admin API get -> returns tags', ['ui', 'markdown'] === (jsonBody($r['body'])['memory']['tags'] ?? []), $r['body']);

// Update only the tags; they must persist (the editor saves tags on blur).
$r = request('POST', '/ui/api/memory', ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf], json_encode([
    'id' => $adminId,
    'category' => 'preferences',
    'title' => 'Editor preference',
    'tags' => 'ui, markdown, editor',
    'content' => 'The user prefers a **two-pane** editor with live preview.',
]));
$tagged = jsonBody($r['body']);
check('admin API save tags -> updated', ['ui', 'markdown', 'editor'] === ($tagged['memory']['tags'] ?? []), $r['body']);

$r = request('POST', '/ui/api/preview', ['Content-Type' => 'application/json'], json_encode([
    'markdown' => "# Heading\n\n**bold** and <script>alert(1)</script>",
]));
$preview = jsonBody($r['body']);
check('admin API preview -> renders Markdown', str_contains($preview['html'] ?? '', '<strong>bold</strong>'), $r['body']);
check('admin API preview -> escapes raw HTML', !str_contains($preview['html'] ?? '', '<script>'));

$r = request('POST', '/ui/api/memory', ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf], json_encode(['category' => '../../evil', 'content' => 'x']));
check('admin API create traversal category -> 400', 400 === $r['status'], (string) $r['status']);

// Move a memory between categories (the drag-and-drop / "move to" flow).
$r = request('POST', '/ui/api/memory', ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf], json_encode([
    'id' => $adminId,
    'category' => 'projects',
    'title' => 'Editor preference',
    'tags' => ['ui', 'markdown'],
    'content' => 'The user prefers a **two-pane** editor with live preview.',
]));
$moved = jsonBody($r['body']);
check('admin API move -> category updated', 'projects' === ($moved['memory']['category'] ?? ''), $r['body']);
$movedFile = $tmp . '/memory/projects/' . $adminId . '.md';
clearstatcache(true, $adminFile);
check('admin API move -> file relocated on disk', is_file($movedFile) && !is_file($adminFile), $movedFile);
check('admin API move -> old location gone', !is_file($adminFile));

// Create a memory directly in a non-default category (new-in-category).
$r = request('POST', '/ui/api/memory', ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf], json_encode([
    'category' => 'people',
    'title' => 'A person',
    'content' => 'Lives in a category chosen at creation time.',
]));
$inCat = jsonBody($r['body']);
$inCatId = $inCat['memory']['id'] ?? '';
check('admin API new-in-category -> created in people', 'people' === ($inCat['memory']['category'] ?? ''), $r['body']);
check('admin API new-in-category -> file in people', '' !== $inCatId && is_file($tmp . '/memory/people/' . $inCatId . '.md'));

// Clean up the extra entry.
request('DELETE', '/ui/api/memory?category=people&id=' . rawurlencode($inCatId), ['X-CSRF-Token' => $apiCsrf]);

// The "notes" catch-all category is accepted and gets its own directory.
$r = request('POST', '/ui/api/memory', ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf], json_encode([
    'category' => 'notes',
    'title' => 'Catch-all note',
    'content' => 'Anything that does not fit another category.',
]));
$note = jsonBody($r['body']);
$noteId = $note['memory']['id'] ?? '';
check('admin API notes category -> created', 'notes' === ($note['memory']['category'] ?? ''), $r['body']);
check('admin API notes category -> file in notes/', '' !== $noteId && is_file($tmp . '/memory/notes/' . $noteId . '.md'));
if ('' !== $noteId) {
    request('DELETE', '/ui/api/memory?category=notes&id=' . rawurlencode($noteId), ['X-CSRF-Token' => $apiCsrf]);
}

// The "workflows" category is a built-in default and usable.
check('workflows category dir auto-created', is_dir($tmp . '/memory/workflows'));
$r = request('POST', '/ui/api/memory', ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf], json_encode([
    'category' => 'workflows',
    'title' => 'Release process',
    'content' => '1. Bump version\n2. Update changelog\n3. Tag\n4. Push.',
]));
$wf = jsonBody($r['body']);
$wfId = $wf['memory']['id'] ?? '';
check('admin API workflows category -> created', 'workflows' === ($wf['memory']['category'] ?? ''), $r['body']);
check('admin API workflows category -> file in workflows/', '' !== $wfId && is_file($tmp . '/memory/workflows/' . $wfId . '.md'));
if ('' !== $wfId) {
    request('DELETE', '/ui/api/memory?category=workflows&id=' . rawurlencode($wfId), ['X-CSRF-Token' => $apiCsrf]);
}

// The admin UI must serve its static assets with sane MIME types, even when
// the host does not rewrite them (PHP built-in server fallback).
$r = request('GET', '/assets/app.css');
check('assets: app.css served as text/css', 200 === $r['status'] && str_starts_with($r['headers']['content-type'] ?? '', 'text/css'), (string) $r['status']);
$r = request('GET', '/assets/app.js');
check('assets: app.js served as javascript', 200 === $r['status'] && str_contains($r['headers']['content-type'] ?? '', 'javascript'), (string) $r['status']);
$r = request('GET', '/assets/favicon.svg');
check('assets: favicon.svg served as image/svg+xml', 200 === $r['status'] && str_contains($r['headers']['content-type'] ?? '', 'image/svg+xml'), (string) $r['status']);
check('assets: favicon.svg has content', 200 === $r['status'] && str_contains($r['body'], '<svg'));
$r = request('GET', '/assets/../config.php');
check('assets: path traversal blocked', 404 === $r['status'], (string) $r['status']);

// Content that already starts with an H1 must not get a duplicated title
// heading when written through the store.
$r = request('POST', '/ui/api/memory', ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf], json_encode([
    'category' => 'context',
    'title' => 'Heading test',
    'content' => "# Heading test\n\nBody under an existing H1.",
]));
$h1doc = jsonBody($r['body']);
$h1id = $h1doc['memory']['id'] ?? '';
$h1raw = '' !== $h1id ? (string) @file_get_contents($tmp . '/memory/context/' . $h1id . '.md') : '';
check('H1: title heading written once', 1 === preg_match_all('/^#\s+Heading test$/m', $h1raw), $h1raw);

// Round-trip: the body returned by the API must not contain the title H1, so
// opening and re-saving never duplicates it (the live-server regression).
$r = request('GET', '/ui/api/memory?category=context&id=' . rawurlencode($h1id));
$loadedBody = jsonBody($r['body'])['memory']['body'] ?? '';
check('H1: GET body excludes the title heading', !str_contains($loadedBody, '# Heading test'), $loadedBody);

$r = request('POST', '/ui/api/memory', ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf], json_encode([
    'id' => $h1id,
    'category' => 'context',
    'title' => 'Heading test renamed',
    'tags' => '',
    'content' => $loadedBody, // as the UI would re-send it
]));
$round = jsonBody($r['body']);
check('H1: round-trip rename -> ok', 200 === $r['status'], $r['body']);
$roundRaw = (string) @file_get_contents($tmp . '/memory/context/' . $h1id . '.md');
check('H1: rename leaves exactly one H1', 1 === preg_match_all('/^#\s+/m', $roundRaw), $roundRaw);
check('H1: rename applied', str_contains($roundRaw, '# Heading test renamed'));

// A pre-corrupted file (several leading H1s) heals to one H1 on save.
$corrupt = $tmp . '/memory/context/' . $h1id . '.md';
file_put_contents($corrupt, "---\ntype: context\nid: {$h1id}\n---\n\n# A\n\n# A\n\n# A\n\nBody.\n");
$r = request('POST', '/ui/api/memory', ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf], json_encode([
    'id' => $h1id, 'category' => 'context', 'title' => 'A', 'tags' => '', 'content' => 'Body.',
]));
$healed = (string) @file_get_contents($corrupt);
check('H1: corrupted duplicates heal to one H1', 1 === preg_match_all('/^#\s+A$/m', $healed), $healed);

if ('' !== $h1id) {
    request('DELETE', '/ui/api/memory?category=context&id=' . rawurlencode($h1id), ['X-CSRF-Token' => $apiCsrf]);
}

$r = request('GET', '/health');
$index = jsonBody($r['body'])['checks']['search_index'] ?? [];
check('health -> reports search index engine', in_array($index['engine'] ?? '', ['sqlite-fts5', 'direct'], true), $r['body']);

// Externally dropped file (e.g. via FTP) is picked up after a search triggers
// an index refresh.
$dropped = $tmp . '/memory/context/dropped-by-ftp.md';
@mkdir(dirname($dropped), 0775, true);
file_put_contents($dropped, "# Dropped by FTP\n\nA memory placed directly on disk.\n");
$r = request('GET', '/ui/api/search?q=' . rawurlencode('dropped ftp'));
check('index: externally dropped .md is found', str_contains($r['body'], 'dropped-by-ftp'), $r['body']);
@unlink($dropped);

// Reindex endpoint (UI button) reports a status object.
$r = request('POST', '/ui/reindex', ['X-CSRF-Token' => $apiCsrf]);
$reindex = jsonBody($r['body']);
check('reindex -> 200 with status', 200 === $r['status'] && isset($reindex['status']['engine']), $r['body']);

$r = request('DELETE', '/ui/api/memory?category=projects&id=' . rawurlencode($adminId), ['X-CSRF-Token' => $apiCsrf]);
check('admin API delete -> 200', 200 === $r['status'], (string) $r['status']);
clearstatcache(true, $movedFile);
check('admin API delete -> Markdown file removed', !is_file($movedFile));

/* ------------------------------------------------------------------ *
 * 10. Archive & tag search
 * ------------------------------------------------------------------ */

section('Archive & tag search');

$r = request('GET', '/');
check('landing page links to admin UI', str_contains($r['body'], 'href="/ui"'), $r['body']);

// Two memories sharing a keyword, one active and one archived.
$r = request('POST', '/ui/api/memory', ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf], json_encode([
    'category' => 'facts',
    'title' => 'Active quokka note',
    'content' => 'The quokka appears here as the active entry.',
]));
$activeDoc = jsonBody($r['body']);
$activeId = $activeDoc['memory']['id'] ?? '';
check('archive: active memory created', 200 === $r['status'] && '' !== $activeId, $r['body']);
check('archive: active memory archived=false', false === ($activeDoc['memory']['archived'] ?? true), $r['body']);

$r = request('POST', '/ui/api/memory', ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf], json_encode([
    'category' => 'facts',
    'title' => 'Archived quokka note',
    'content' => 'The quokka appears here as the stored entry.',
    'archived' => true,
    'tags' => ['tagonly', 'quokka'],
]));
$archDoc = jsonBody($r['body']);
$archId = $archDoc['memory']['id'] ?? '';
check('archive: archived memory created', 200 === $r['status'] && '' !== $archId, $r['body']);
check('archive: archived flag returned', true === ($archDoc['memory']['archived'] ?? false), $r['body']);

$archFile = $tmp . '/memory/facts/' . $archId . '.md';
$archRaw = is_file($archFile) ? (string) file_get_contents($archFile) : '';
check('archive: frontmatter archived: true', str_contains($archRaw, 'archived: true'), $archRaw);

// Default search returns both, with active ranked before archived.
$r = request('GET', '/ui/api/search?q=quokka');
$results = jsonBody($r['body'])['results'] ?? [];
$ids = array_column($results, 'id');
check('search: default includes archived', in_array($archId, $ids, true), $r['body']);
check('search: active ranked before archived', ($ids[0] ?? '') === $activeId, json_encode($ids));
$archPos = array_search($archId, $ids, true);
check('search: archived flag in results', false !== $archPos && true === ($results[$archPos]['archived'] ?? false), $r['body']);

// Filter modes.
$r = request('GET', '/ui/api/search?q=quokka&archived=archived');
$ids = array_column(jsonBody($r['body'])['results'] ?? [], 'id');
check('search: archived filter returns only archived', in_array($archId, $ids, true) && !in_array($activeId, $ids, true), $r['body']);
$r = request('GET', '/ui/api/search?q=quokka&archived=active');
$ids = array_column(jsonBody($r['body'])['results'] ?? [], 'id');
check('search: active filter returns only active', in_array($activeId, $ids, true) && !in_array($archId, $ids, true), $r['body']);

// tag: query syntax (standalone, no free-text terms).
$r = request('GET', '/ui/api/search?q=' . rawurlencode('tag:tagonly'));
$ids = array_column(jsonBody($r['body'])['results'] ?? [], 'id');
check('search: tag: syntax finds tagged entry', in_array($archId, $ids, true), $r['body']);
$r = request('GET', '/ui/api/search?q=' . rawurlencode('tag:does-not-exist'));
check('search: tag: syntax no false positives', [] === (jsonBody($r['body'])['results'] ?? []), $r['body']);

// tag: is exact (no prefix) and scoped to tags, never body text.
$r = request('POST', '/ui/api/memory', ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf], json_encode([
    'category' => 'facts', 'title' => 'Tag prefix probe', 'content' => 'unrelated body', 'tags' => ['tagonlyextra'],
]));
$extraTagId = jsonBody($r['body'])['memory']['id'] ?? '';
$r = request('POST', '/ui/api/memory', ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf], json_encode([
    'category' => 'facts', 'title' => 'Body mention probe', 'content' => 'This body mentions tagonly but carries no such tag.',
]));
$bodyMentionId = jsonBody($r['body'])['memory']['id'] ?? '';

$r = request('GET', '/ui/api/search?q=' . rawurlencode('tag:tagonly'));
$ids = array_column(jsonBody($r['body'])['results'] ?? [], 'id');
check('search: tag: finds the exact tag', in_array($archId, $ids, true), $r['body']);
check('search: tag: exact, no prefix match', !in_array($extraTagId, $ids, true), $r['body']);
check('search: tag: does not match body text', !in_array($bodyMentionId, $ids, true), $r['body']);

// text AND tag: both must match the same entry.
$r = request('POST', '/ui/api/memory', ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf], json_encode([
    'category' => 'facts', 'title' => 'Zebra mix', 'content' => 'zebra in the mix', 'tags' => ['mix'],
]));
$mixA = jsonBody($r['body'])['memory']['id'] ?? '';
$r = request('POST', '/ui/api/memory', ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf], json_encode([
    'category' => 'facts', 'title' => 'Mix only', 'content' => 'nothing here', 'tags' => ['mix'],
]));
$mixB = jsonBody($r['body'])['memory']['id'] ?? '';

$r = request('GET', '/ui/api/search?q=' . rawurlencode('zebra tag:mix'));
$ids = array_column(jsonBody($r['body'])['results'] ?? [], 'id');
check('search: text AND tag requires both', in_array($mixA, $ids, true) && !in_array($mixB, $ids, true), json_encode($ids));

$r = request('GET', '/ui/api/search?q=' . rawurlencode('tag:mix'));
$ids = array_column(jsonBody($r['body'])['results'] ?? [], 'id');
check('search: tag: alone matches all with the tag', in_array($mixA, $ids, true) && in_array($mixB, $ids, true), json_encode($ids));

// Unarchive via admin API removes the frontmatter key.
$r = request('POST', '/ui/api/memory', ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf], json_encode([
    'id' => $archId,
    'category' => 'facts',
    'title' => 'Archived quokka note',
    'content' => 'The quokka appears here as the stored entry.',
    'tags' => ['tagonly', 'quokka'],
    'archived' => false,
]));
$unarch = jsonBody($r['body']);
check('archive: unarchive via save', false === ($unarch['memory']['archived'] ?? true), $r['body']);
$archRaw = (string) @file_get_contents($archFile);
check('archive: unarchive removes frontmatter key', !str_contains($archRaw, 'archived:'), $archRaw);

// MCP tools: archive on remember, filters on search/update.
$r = request('POST', '/mcp', $session, json_encode([
    'jsonrpc' => '2.0', 'id' => 20, 'method' => 'tools/call',
    'params' => ['name' => 'remember', 'arguments' => [
        'content' => 'MCP archived entry about narwhal.', 'title' => 'MCP archived narwhal',
        'category' => 'facts', 'tags' => ['narwhal'], 'archived' => true,
    ]],
]));
$mcpArch = jsonBody($r['body']);
$mcpArchId = toolResult($mcpArch)['memory']['id'] ?? '';
check('mcp remember archived -> created', false === ($mcpArch['result']['isError'] ?? true) && true === (toolResult($mcpArch)['memory']['archived'] ?? false), $r['body']);

$r = request('POST', '/mcp', $session, json_encode([
    'jsonrpc' => '2.0', 'id' => 21, 'method' => 'tools/call',
    'params' => ['name' => 'search_memory', 'arguments' => ['query' => 'narwhal', 'archived' => 'archived']],
]));
$mcpSearch = toolResult(jsonBody($r['body']));
check('mcp search archived filter -> found', '' !== $mcpArchId && ($mcpSearch['results'][0]['id'] ?? '') === $mcpArchId, $r['body']);

$r = request('POST', '/mcp', $session, json_encode([
    'jsonrpc' => '2.0', 'id' => 22, 'method' => 'tools/call',
    'params' => ['name' => 'update_memory', 'arguments' => ['id' => $mcpArchId, 'archived' => false]],
]));
$mcpUnarch = toolResult(jsonBody($r['body']));
check('mcp update_memory unarchive -> active', false === ($mcpUnarch['memory']['archived'] ?? true), $r['body']);

$r = request('POST', '/mcp', $session, json_encode([
    'jsonrpc' => '2.0', 'id' => 23, 'method' => 'tools/call',
    'params' => ['name' => 'search_memory', 'arguments' => ['query' => 'tag:narwhal']],
]));
check('mcp search tag: syntax -> found', '' !== $mcpArchId && ((toolResult(jsonBody($r['body']))['results'][0]['id'] ?? '') === $mcpArchId), $r['body']);

// Clean up the archive-test entries.
request('DELETE', '/ui/api/memory?category=facts&id=' . rawurlencode($activeId), ['X-CSRF-Token' => $apiCsrf]);
request('DELETE', '/ui/api/memory?category=facts&id=' . rawurlencode($archId), ['X-CSRF-Token' => $apiCsrf]);
request('POST', '/mcp', $session, json_encode([
    'jsonrpc' => '2.0', 'id' => 24, 'method' => 'tools/call',
    'params' => ['name' => 'forget_memory', 'arguments' => ['id' => $mcpArchId, 'category' => 'facts']],
]));

$r = request('POST', '/ui/logout', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['csrf' => $apiCsrf]));
check('admin logout -> 302', 302 === $r['status'], (string) $r['status']);
$r = request('GET', '/ui');
check('GET /ui after logout -> 302 to login', 302 === $r['status'] && str_contains($r['headers']['location'] ?? '', '/ui/login'));

/* ------------------------------------------------------------------ *
 *  Summary
 * ------------------------------------------------------------------ */

echo "\n----------------------------------------\n";
echo "Passed: {$passed}   Failed: {$failed}\n";
if ([] !== $failures) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
}

if (!$keepServer && is_resource($serverProc)) {
    proc_terminate($serverProc);
    proc_close($serverProc);
}

exit($failed > 0 ? 1 : 0);
