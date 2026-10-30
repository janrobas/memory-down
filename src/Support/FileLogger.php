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
 *
 * The file is rotated when it would outgrow `maxBytes` (0 = never): the
 * current file is renamed to `app.log.1` and a fresh one is started, so a
 * shared-hosting install cannot quietly fill its quota with logs. Rotation is
 * best-effort — if the rename fails, the line is appended anyway, because a
 * too-large log is better than a silently lost log line.
 */
final class FileLogger extends AbstractLogger
{
    private string $requestId;

    public function __construct(
        private readonly string $file,
        private readonly string $channel = 'memorydown',
        string $requestId = '',
        private readonly int $maxBytes = 0,
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

        $this->append($line . "\n");
    }

    /**
     * Append one line, rotating first when the file would outgrow maxBytes.
     */
    private function append(string $line): void
    {
        $handle = @fopen($this->file, 'a');
        if (false === $handle) {
            return;
        }

        @flock($handle, LOCK_EX);
        $size = (int) (fstat($handle)['size'] ?? 0);

        if ($this->maxBytes > 0 && $size > 0 && $size + strlen($line) > $this->maxBytes) {
            @flock($handle, LOCK_UN);
            fclose($handle);

            @unlink($this->file . '.1');
            @rename($this->file, $this->file . '.1');

            $handle = @fopen($this->file, 'a');
            if (false === $handle) {
                return;
            }
            @flock($handle, LOCK_EX);
        }

        @fwrite($handle, $line);
        @flock($handle, LOCK_UN);
        fclose($handle);
    }
}
