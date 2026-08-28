<?php

declare(strict_types=1);

namespace MemoryDown\Support;

use Psr\Log\AbstractLogger;

/**
 * Minimal PSR-3 logger writing one JSON line per event.
 *
 * Never log secrets: callers must not pass tokens, passwords, authorization
 * codes or client secrets in the context. The logger itself logs only the
 * event message plus scalar context it was given.
 */
final class FileLogger extends AbstractLogger
{
    private string $requestId;

    public function __construct(
        private readonly string $file,
        private readonly string $channel = 'memorydown',
        string $requestId = '',
    ) {
        $this->requestId = $requestId !== '' ? $requestId : self::generateRequestId();
    }

    public static function generateRequestId(): string
    {
        return bin2hex(random_bytes(8));
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $entry = [
            'ts' => gmdate('c'),
            'level' => strtoupper((string) $level),
            'channel' => $this->channel,
            'request_id' => $this->requestId,
            'message' => (string) $message,
        ];

        if ([] !== $context) {
            $entry['context'] = $context;
        }

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES);
        if (false === $line) {
            return;
        }

        @file_put_contents($this->file, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
