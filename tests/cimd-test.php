<?php

declare(strict_types=1);

/**
 * Unit test for CIMD document validation (the part that broke ChatGPT's
 * OAuth setup). No network access required.
 *
 *   php tests/cimd-test.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use MemoryDown\Auth\CimdFetcher;

$tmp = sys_get_temp_dir() . '/memorydown-cimd-test';
@mkdir($tmp . '/cimd', 0775, true);
$fetcher = new CimdFetcher($tmp, ['chatgpt.com']);

$passed = 0;
$failed = 0;

function check(string $name, bool $ok): void
{
    global $passed, $failed;
    if ($ok) {
        ++$passed;
        echo "  PASS  {$name}\n";
    } else {
        ++$failed;
        echo "  FAIL  {$name}\n";
    }
}

$url = 'https://chatgpt.com/oauth/client.json';

// A realistic ChatGPT CIMD document: legacy singular field is private_key_jwt,
// plural field lists both none and private_key_jwt.
$chatgptDoc = [
    'client_id' => $url,
    'client_name' => 'ChatGPT',
    'redirect_uris' => [
        'https://chatgpt.com/connector_platform_oauth_redirect',
        'https://chatgpt.com/connector/oauth/abc123',
    ],
    'token_endpoint_auth_methods_supported' => ['none', 'private_key_jwt'],
    'token_endpoint_auth_method' => 'private_key_jwt',
];

$client = $fetcher->validateDocument($chatgptDoc, $url);
check('ChatGPT doc (private_key_jwt legacy) accepted', null !== $client);
check('client_id preserved', null !== $client && $client['client_id'] === $url);
check('redirect_uris preserved', null !== $client && in_array('https://chatgpt.com/connector_platform_oauth_redirect', $client['redirect_uris'], true));
check('token_endpoint_auth_method normalized to none', null !== $client && 'none' === $client['token_endpoint_auth_method']);

// Plural-only form (no legacy singular field).
$pluralOnly = $chatgptDoc;
unset($pluralOnly['token_endpoint_auth_method']);
check('plural-only ["none","private_key_jwt"] accepted', null !== $fetcher->validateDocument($pluralOnly, $url));

// Singular "none" form.
$singularNone = $chatgptDoc;
unset($singularNone['token_endpoint_auth_methods_supported']);
$singularNone['token_endpoint_auth_method'] = 'none';
check('singular "none" accepted', null !== $fetcher->validateDocument($singularNone, $url));

// Client that only supports private_key_jwt (no "none") is rejected.
$noNone = $chatgptDoc;
$noNone['token_endpoint_auth_methods_supported'] = ['private_key_jwt'];
unset($noNone['token_endpoint_auth_method']);
check('client without "none" rejected', null === $fetcher->validateDocument($noNone, $url));

// client_id mismatch rejected.
$mismatch = $chatgptDoc;
$mismatch['client_id'] = 'https://evil.example.com/client.json';
check('client_id mismatch rejected', null === $fetcher->validateDocument($mismatch, $url));

// Missing client_name rejected.
$noName = $chatgptDoc;
unset($noName['client_name']);
check('missing client_name rejected', null === $fetcher->validateDocument($noName, $url));

// Missing redirect_uris rejected.
$noRedirect = $chatgptDoc;
unset($noRedirect['redirect_uris']);
check('missing redirect_uris rejected', null === $fetcher->validateDocument($noRedirect, $url));

// Non-loopback http redirect URI rejected.
$badRedirect = $chatgptDoc;
$badRedirect['redirect_uris'] = ['http://evil.example.com/cb'];
check('non-loopback http redirect rejected', null === $fetcher->validateDocument($badRedirect, $url));

echo "\nPassed: {$passed}   Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
