<?php

declare(strict_types=1);

/**
 * Fetch the on-device category-suggester assets (Laya).
 *
 * Run this LOCALLY before deploying (the model weights are intentionally not
 * committed to git). It downloads, into public_html/assets/laya/:
 *
 *   runtime/                          transformers.js v3 browser bundle +
 *                                     matching ONNX Runtime WebAssembly files
 *   models/<MODEL_ID>/                the quantized embedding model
 *
 * Usage:
 *   php tools/fetch-laya.php          # download anything that is missing
 *   php tools/fetch-laya.php --check  # report missing files, download nothing
 *   php tools/fetch-laya.php --force  # re-download even if present
 *
 * Nothing here runs on the shared host; it is a build/deploy helper only.
 *
 * Downloads use cURL when available: Hugging Face redirects large (LFS) files
 * to a signed CDN URL that PHP's stream wrapper mishandles. A CA bundle is
 * required for TLS; one is taken from php.ini if configured, otherwise
 * downloaded once to the system temp directory.
 */

const TRANSFORMERS_VERSION = '3.8.1';
const MODEL_ID = 'Xenova/multilingual-e5-small';

$root = dirname(__DIR__);
$layaDir = $root . '/public_html/assets/laya';
$runtimeDir = $layaDir . '/runtime';
$modelDir = $layaDir . '/models/' . MODEL_ID;

// Files the transformers.js v3 bundle needs from the model repo.
// model_quantized.onnx is the q8 build used by the WASM backend.
const MODEL_FILES = [
    'config.json',
    'tokenizer.json',
    'tokenizer_config.json',
    'special_tokens_map.json',
    'sentencepiece.bpe.model',
    'onnx/model_quantized.onnx',
];

$args = array_slice($argv, 1);
$check = in_array('--check', $args, true);
$force = in_array('--force', $args, true);

function out(string $msg): void
{
    fwrite(STDOUT, $msg . "\n");
}

function fail(string $msg): never
{
    fwrite(STDERR, 'ERROR: ' . $msg . "\n");
    exit(1);
}

function format_bytes(int $n): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $v = (float) $n;
    while ($v >= 1024 && $i < count($units) - 1) {
        $v /= 1024;
        $i++;
    }

    return ($i === 0 ? (string) (int) $v : number_format($v, 1)) . ' ' . $units[$i];
}

/** Path to a usable CA bundle, downloading one to temp if php.ini has none. */
function ca_bundle(): ?string
{
    foreach (['curl.cainfo', 'openssl.cafile'] as $key) {
        $path = ini_get($key);
        if (is_string($path) && '' !== $path && is_file($path)) {
            return $path;
        }
    }

    $cache = sys_get_temp_dir() . '/memorydown-cacert.pem';
    if (is_file($cache) && filesize($cache) > 1024) {
        return $cache;
    }

    out('  downloading CA bundle (curl.se)...');
    $ctx = stream_context_create(['http' => ['timeout' => 60, 'follow_location' => 1], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $pem = @file_get_contents('https://curl.se/ca/cacert.pem', false, $ctx);
    if (is_string($pem) && strlen($pem) > 1024 && @file_put_contents($cache, $pem) !== false) {
        return $cache;
    }

    return null;
}

/** Download $url into $dest (creating parent dirs). Returns bytes written. */
function download(string $url, string $dest): int
{
    $dir = dirname($dest);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fail('cannot create directory: ' . $dir);
    }

    $tmp = $dest . '.part';
    if (extension_loaded('curl')) {
        $bytes = download_curl($url, $tmp);
    } else {
        $bytes = download_stream($url, $tmp);
    }

    if ($bytes <= 0) {
        @unlink($tmp);
        fail('empty/failed download: ' . $url);
    }

    if (!@rename($tmp, $dest)) {
        @unlink($tmp);
        fail('cannot move into place: ' . $dest);
    }

    return $bytes;
}

function download_curl(string $url, string $dest): int
{
    $fh = @fopen($dest, 'wb');
    if (false === $fh) {
        fail('cannot write: ' . $dest);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_USERAGENT => 'MemoryDown-fetch-laya/1.0',
        CURLOPT_FAILONERROR => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $ca = ca_bundle();
    if (null !== $ca) {
        curl_setopt($ch, CURLOPT_CAINFO, $ca);
    }

    $ok = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fh);

    if (false === $ok) {
        // Some libcurl builds have no CA store; retry once with verification off.
        if (str_contains(strtolower($err), 'certificate')) {
            out('  (TLS verify failed; retrying without peer verification)');
            return download_curl_insecure($url, $dest);
        }
        fail('curl download failed: ' . $url . ' (' . $err . ')');
    }

    return (int) @filesize($dest);
}

function download_curl_insecure(string $url, string $dest): int
{
    $fh = @fopen($dest, 'wb');
    if (false === $fh) {
        fail('cannot write: ' . $dest);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_USERAGENT => 'MemoryDown-fetch-laya/1.0',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $ok = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fh);
    if (false === $ok) {
        fail('curl download failed: ' . $url . ' (' . $err . ')');
    }

    return (int) @filesize($dest);
}

function download_stream(string $url, string $dest): int
{
    $ctx = stream_context_create([
        'http' => ['timeout' => 600, 'follow_location' => 1, 'max_redirects' => 10, 'user_agent' => 'MemoryDown-fetch-laya/1.0'],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true],
    ]);
    $in = @fopen($url, 'rb', false, $ctx);
    if (false === $in) {
        return 0;
    }
    $out = @fopen($dest, 'wb');
    if (false === $out) {
        fclose($in);

        return 0;
    }
    $n = @stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);

    return false === $n ? 0 : (int) $n;
}

/** @return list<string> */
function missing_files(string $runtimeDir, string $modelDir): array
{
    $missing = [];
    if (!is_file($runtimeDir . '/transformers.min.js')) {
        $missing[] = 'runtime/transformers.min.js';
    }
    foreach (MODEL_FILES as $file) {
        if (!is_file($modelDir . '/' . $file)) {
            $missing[] = 'models/' . MODEL_ID . '/' . $file;
        }
    }

    return $missing;
}

function delete_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

if ($check) {
    $missing = missing_files($runtimeDir, $modelDir);
    if ([] === $missing) {
        out('Laya assets present.');
        exit(0);
    }
    out('Missing Laya assets:');
    foreach ($missing as $file) {
        out('  - ' . $file);
    }
    out('Run: php tools/fetch-laya.php');
    exit(1);
}

out('Fetching Laya runtime (transformers.js v' . TRANSFORMERS_VERSION . ')...');

if ($force || !is_file($runtimeDir . '/transformers.min.js')) {
    $tgz = sys_get_temp_dir() . '/transformers-' . TRANSFORMERS_VERSION . '.tgz';
    $url = 'https://registry.npmjs.org/@huggingface/transformers/-/transformers-' . TRANSFORMERS_VERSION . '.tgz';
    out('  downloading ' . $url);
    $bytes = download($url, $tgz);
    out('  ' . format_bytes($bytes));

    $extract = sys_get_temp_dir() . '/transformers-' . TRANSFORMERS_VERSION . '-x';
    delete_tree($extract);
    mkdir($extract, 0775, true);

    try {
        (new PharData($tgz))->extractTo($extract, null, true);
    } catch (Throwable $e) {
        fail('cannot extract ' . $tgz . ': ' . $e->getMessage());
    }

    $dist = $extract . '/package/dist';
    if (!is_dir($dist)) {
        fail('unexpected package layout (no dist/): ' . $dist);
    }
    if (!is_dir($runtimeDir)) {
        mkdir($runtimeDir, 0775, true);
    }
    foreach (scandir($dist) ?: [] as $entry) {
        if ('.' === $entry || '..' === $entry) {
            continue;
        }
        $from = $dist . '/' . $entry;
        if (is_file($from)) {
            copy($from, $runtimeDir . '/' . $entry);
        }
    }
    out('  runtime -> assets/laya/runtime/');
    @unlink($tgz);
    delete_tree($extract);
} else {
    out('  runtime already present (use --force to refresh)');
}

out('Fetching model ' . MODEL_ID . '...');
$base = 'https://huggingface.co/' . MODEL_ID . '/resolve/main/';
foreach (MODEL_FILES as $file) {
    $dest = $modelDir . '/' . $file;
    if (!$force && is_file($dest)) {
        out('  ' . $file . ' (present)');
        continue;
    }
    $bytes = download($base . $file, $dest);
    out(sprintf('  %-52s %s', $file, format_bytes($bytes)));
}

$missing = missing_files($runtimeDir, $modelDir);
if ([] !== $missing) {
    out('WARNING: still missing: ' . implode(', ', $missing));
    exit(1);
}

out('Done. Now upload public_html/ (including assets/laya/) via FTP.');
