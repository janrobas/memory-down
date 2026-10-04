<?php

declare(strict_types=1);

namespace MemoryDown\Support;

/**
 * Tiny fixed-window rate limiter backed by one file per key.
 *
 * No daemon, no database: each request opens its bucket file, takes an
 * exclusive lock, and increments a counter inside the current window. This
 * suits ordinary PHP shared hosting where a process cannot keep state in
 * memory. Buckets live under the (already writable, git-ignored) auth data
 * directory.
 */
final class RateLimiter
{
    public function __construct(
        private readonly string $dir,
        private readonly bool $enabled = true,
    ) {
    }

    /**
     * Record one hit for $key and report whether it is within $max per
     * $windowSeconds. Fails open (allows) when the bucket cannot be written,
     * so a read-only host degrades to no limiting rather than blocking auth.
     */
    public function allow(string $key, int $max, int $windowSeconds): bool
    {
        if (!$this->enabled || $max <= 0 || $windowSeconds <= 0) {
            return true;
        }

        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            return true;
        }

        $file = $this->dir . '/' . hash('sha256', $key) . '.json';
        $handle = @fopen($file, 'c+');
        if (false === $handle) {
            return true;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return true;
            }

            $now = time();
            $raw = stream_get_contents($handle);
            $state = is_string($raw) ? json_decode($raw, true) : null;
            $start = is_array($state) ? (int) ($state['start'] ?? 0) : 0;
            $count = is_array($state) ? (int) ($state['count'] ?? 0) : 0;

            if ($now - $start >= $windowSeconds || $now < $start) {
                $start = $now;
                $count = 0;
            }

            $allowed = $count < $max;
            ++$count;

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode(['start' => $start, 'count' => $count]));
            fflush($handle);
            flock($handle, LOCK_UN);

            return $allowed;
        } finally {
            fclose($handle);
        }
    }
}
