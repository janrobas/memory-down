<?php

declare(strict_types=1);

namespace MemoryDown\Support;

/**
 * A minimal, dependency-free ZIP writer.
 *
 * The zip extension is not a declared requirement of MemoryDown and is missing
 * from some PHP builds (including the one this project is tested with), so the
 * backup download cannot rely on ZipArchive. Only the slice of the format a
 * normal unzip tool needs is implemented: a local header per entry, one central
 * directory and the end-of-central-directory record. Entries are deflated when
 * zlib is available and stored verbatim otherwise.
 *
 * Deliberately absent: Zip64, encryption, comments, multi-disk archives and
 * per-entry extra fields. A personal Markdown store is nowhere near the 4 GB
 * boundary where any of that would matter.
 *
 * The archive is assembled in memory — callers add entries, then finish()
 * returns the whole thing as a string.
 */
final class ZipWriter
{
    private const LOCAL_HEADER = 0x04034b50;
    private const CENTRAL_HEADER = 0x02014b50;
    private const END_OF_CENTRAL = 0x06054b50;

    /** Format version 2.0 (the one that introduced deflate). */
    private const VERSION = 20;

    private const METHOD_STORE = 0;
    private const METHOD_DEFLATE = 8;

    /** General-purpose bit 11: the file name is UTF-8. */
    private const FLAG_UTF8 = 0x0800;

    /** Unix, for the external-attribute half of "version made by". */
    private const MADE_BY_UNIX = 3;

    private const UNIX_MODE_FILE = 0644;

    private string $body = '';

    /** @var list<array{name: string, method: int, crc: int, size: int, packed: int, offset: int, time: int, date: int}> */
    private array $entries = [];

    private int $offset = 0;

    /**
     * Add one file. $mtime is a Unix timestamp used for the entry's DOS
     * timestamp; it is cosmetic, but keeping the real one makes an extracted
     * tree look right in a file manager.
     */
    public function add(string $name, string $contents, ?int $mtime = null): void
    {
        $crc = crc32($contents);
        $size = strlen($contents);

        // gzdeflate() emits raw deflate, which is exactly what a zip entry
        // holds. Storing beats deflating for tiny or incompressible payloads.
        $deflated = function_exists('gzdeflate') ? gzdeflate($contents, 9) : false;
        $useDeflate = false !== $deflated && strlen((string) $deflated) < $size;
        $payload = $useDeflate ? (string) $deflated : $contents;

        [$time, $date] = self::dosTimestamp($mtime ?? time());

        $header = pack(
            'VvvvvvVVVvv',
            self::LOCAL_HEADER,
            self::VERSION,
            self::FLAG_UTF8,
            $useDeflate ? self::METHOD_DEFLATE : self::METHOD_STORE,
            $time,
            $date,
            $crc,
            strlen($payload),
            $size,
            strlen($name),
            0
        );

        $this->body .= $header . $name . $payload;
        $this->entries[] = [
            'name' => $name,
            'method' => $useDeflate ? self::METHOD_DEFLATE : self::METHOD_STORE,
            'crc' => $crc,
            'size' => $size,
            'packed' => strlen($payload),
            'offset' => $this->offset,
            'time' => $time,
            'date' => $date,
        ];
        $this->offset += strlen($header) + strlen($name) + strlen($payload);
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Close the archive: emit the central directory and the end record, then
     * return the complete ZIP as a binary string.
     */
    public function finish(): string
    {
        $directory = '';
        foreach ($this->entries as $entry) {
            $directory .= pack(
                'VvvvvvvVVVvvvvvVV',
                self::CENTRAL_HEADER,
                (self::MADE_BY_UNIX << 8) | self::VERSION,
                self::VERSION,
                self::FLAG_UTF8,
                $entry['method'],
                $entry['time'],
                $entry['date'],
                $entry['crc'],
                $entry['packed'],
                $entry['size'],
                strlen($entry['name']),
                0,
                0,
                0,
                0,
                self::UNIX_MODE_FILE << 16,
                $entry['offset']
            ) . $entry['name'];
        }

        $count = count($this->entries);

        return $this->body . $directory . pack(
            'VvvvvVVv',
            self::END_OF_CENTRAL,
            0,
            0,
            $count,
            $count,
            strlen($directory),
            $this->offset,
            0
        );
    }

    /**
     * A Unix timestamp as the (time, date) pair of 16-bit fields the DOS format
     * uses. Clamped to its 1980 lower bound; dates past 2107 are not a concern.
     *
     * @return array{0: int, 1: int}
     */
    private static function dosTimestamp(int $timestamp): array
    {
        $parts = getdate($timestamp);
        $year = max(1980, (int) $parts['year']);

        $time = ((int) $parts['hours'] << 11) | ((int) $parts['minutes'] << 5) | ((int) $parts['seconds'] >> 1);
        $date = (($year - 1980) << 9) | ((int) $parts['mon'] << 5) | (int) $parts['mday'];

        return [$time & 0xFFFF, $date & 0xFFFF];
    }
}
