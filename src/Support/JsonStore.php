<?php

declare(strict_types=1);

namespace MemoryDown\Support;

/**
 * A tiny atomic JSON file store, safe for concurrent requests on shared
 * hosting (flock-protected read-modify-write).
 *
 * Used only for ephemeral/credential state (OAuth codes, tokens, registered
 * clients). Persistent memory itself is Markdown files, never this store.
 */
final class JsonStore
{
    public function __construct(
        private readonly string $file,
    ) {
    }

    public function path(): string
    {
        return $this->file;
    }

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $raw = file_get_contents($this->file);
        if (false === $raw || '' === $raw) {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }

        return $data;
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutate
     *
     * @return array<string, mixed> the result of the mutation
     */
    public function mutate(callable $mutate): array
    {
        $handle = fopen($this->file, 'c+');
        if (false === $handle) {
            throw new \RuntimeException("Cannot open store: {$this->file}");
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException("Cannot lock store: {$this->file}");
            }

            // Read the whole file from the start. Do not gate on filesize():
            // PHP's stat cache can return a stale size after a previous
            // truncate+write in the same process, which would make the store
            // look empty and silently drop records.
            rewind($handle);
            $raw = stream_get_contents($handle);
            $data = [];
            if (is_string($raw) && '' !== $raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }

            $data = $mutate($data);

            if (!is_array($data)) {
                throw new \RuntimeException('JsonStore mutation must return an array.');
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            fflush($handle);
            flock($handle, LOCK_UN);

            return $data;
        } finally {
            fclose($handle);
        }
    }

    public function write(array $data): void
    {
        $this->mutate(static fn (): array => $data);
    }

    /**
     * @param array<string, mixed> $value
     */
    public function put(string $key, array $value): void
    {
        $this->mutate(static function (array $data) use ($key, $value): array {
            $data[$key] = $value;

            return $data;
        });
    }

    public function get(string $key): ?array
    {
        $data = $this->read();

        return isset($data[$key]) && is_array($data[$key]) ? $data[$key] : null;
    }

    public function remove(string $key): void
    {
        $this->mutate(static function (array $data) use ($key): array {
            unset($data[$key]);

            return $data;
        });
    }

    /**
     * Drop every record older than $expiresAt (unix timestamp).
     */
    public function gc(int $expiresAt): int
    {
        $removed = 0;
        $this->mutate(static function (array $data) use ($expiresAt, &$removed): array {
            foreach ($data as $key => $record) {
                if (is_array($record) && isset($record['expires_at']) && (int) $record['expires_at'] < $expiresAt) {
                    unset($data[$key]);
                    ++$removed;
                }
            }

            return $data;
        });

        return $removed;
    }
}
