<?php

declare(strict_types=1);

/**
 * MemoryDown front controller.
 *
 * Every request (health, discovery, OAuth, MCP) is routed through this file.
 * Point the domain/subdomain document root at this directory.
 */

// The MCP transport streams SSE responses; disable output buffering so events
// are flushed as they are produced rather than at the end of the request.
while (ob_get_level() > 0) {
    ob_end_clean();
}
@ini_set('zlib.output_compression', '0');

require dirname(__DIR__) . '/vendor/autoload.php';

$config = require dirname(__DIR__) . '/config.php';

MemoryDown\Kernel::boot($config);

MemoryDown\Http\App::fromGlobals()->run();
