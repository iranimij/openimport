<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Archive;

use Iranimij\OpenImport\Model\Storage\TempFolder;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File;

/**
 * Takes the file to read out of a ZIP, gzip, tar or tar.gz archive, before the reader sees it.
 *
 * Safety:
 * - Entry names never become paths: the chosen entry is written to one fixed file name in the target folder, so
 *   "../" names (zip-slip), absolute names and links cannot write anywhere else. Links and devices are skipped.
 * - The unpacked data is counted while it is written, not taken from the archive's headers: more than the limit
 *   (1 GB by default) stops with a message (zip bombs). Archives with more than 10,000 entries are refused.
 * - Archives inside archives are not unpacked; password-protected entries are refused.
 */
class Unpacker
{
    public const MODE_AUTO = 'auto';
    public const MODE_NONE = 'none';

    public const KIND_ZIP = 'zip';
    public const KIND_GZ = 'gz';
    public const KIND_TAR = 'tar';
    public const KIND_TAR_GZ = 'tar.gz';

    /** Extensions of files the readers understand: the file taken when no pattern is set. */
    private const READABLE = ['csv', 'tsv', 'txt', 'xlsx', 'ods', 'json', 'xml'];

    private const ARCHIVE_NAME = '/\.(zip|gz|tgz|tar|bz2|7z|rar)$/i';

    private const CHUNK = 1048576;

    /**
     * @param File $files
     * @param TempFolder $tempFolder
     * @param int $maxBytes largest unpacked file (and largest unpacked tar)
     * @param int $maxEntries
     */
    public function __construct(
        private readonly File $files,
        private readonly TempFolder $tempFolder,
        private readonly int $maxBytes = 1073741824,
        private readonly int $maxEntries = 10000
    ) {
    }

    /**
     * Runs $read on the file to read: the file itself, or the file taken out of it into a temporary folder that is
     * removed afterwards (form previews and tests of a source).
     *
     * @param string $file absolute path
     * @param string $name original file name
     * @param array<string, mixed> $formatOptions the profile's format options (archive, archive_entry)
     * @param callable(string, string): mixed $read gets the path and the name of the file to read
     * @return mixed what $read returns
     * @throws ArchiveException
     */
    public function withFile(string $file, string $name, array $formatOptions, callable $read): mixed
    {
        if ($this->kindFor($file, $name, $formatOptions) === '') {
            return $read($file, $name);
        }
        return $this->tempFolder->with('unpack', function (string $folder) use ($file, $name, $formatOptions, $read) {
            $unpacked = $this->unpack($file, $name, $folder, $formatOptions);

            return $read($unpacked->path, $unpacked->name);
        });
    }

    /**
     * The archive kind to unpack for a profile: '' when the file is read as it is.
     *
     * @param string $file
     * @param string $name
     * @param array<string, mixed> $formatOptions
     * @return string
     */
    public function kindFor(string $file, string $name, array $formatOptions): string
    {
        $mode = (string) ($formatOptions['archive'] ?? self::MODE_AUTO);

        return $mode === self::MODE_NONE ? '' : $this->kind($file, $name);
    }

    /**
     * What kind of archive a file is, from its name and else its first bytes; '' for anything else. Excel and
     * OpenDocument files are ZIP files too: they are never taken for archives.
     *
     * @param string $file absolute path
     * @param string $name original file name ('' = the path's)
     * @return string one of the KIND_ constants or ''
     */
    public function kind(string $file, string $name = ''): string
    {
        $name = strtolower($name !== '' ? $name : $file);
        if (preg_match('/\.(xlsx|ods)$/', $name) === 1) {
            return '';
        }
        if (preg_match('/\.(tar\.gz|tgz)$/', $name) === 1) {
            return self::KIND_TAR_GZ;
        }
        foreach ([self::KIND_GZ, self::KIND_ZIP, self::KIND_TAR] as $kind) {
            if (str_ends_with($name, '.' . $kind)) {
                return $kind;
            }
        }
        $head = $this->head($file, 512);
        if (str_starts_with($head, "\x1F\x8B")) {
            return self::KIND_GZ;
        }
        if (substr($head, 257, 5) === 'ustar') {
            return self::KIND_TAR;
        }
        if (str_starts_with($head, "PK\x03\x04") && !$this->isOfficeFile($file)) {
            return self::KIND_ZIP;
        }

        return '';
    }

    /**
     * Takes the file to read out of an archive.
     *
     * @param string $file absolute path of the archive
     * @param string $name its original name
     * @param string $targetDir writable folder for the unpacked file
     * @param array<string, mixed> $formatOptions archive_entry: pattern of the file inside (empty = the first
     *                                            CSV, Excel, ODS, JSON or XML file by name)
     * @return Unpacked
     * @throws ArchiveException
     */
    public function unpack(string $file, string $name, string $targetDir, array $formatOptions = []): Unpacked
    {
        $pattern = trim((string) ($formatOptions['archive_entry'] ?? ''));
        $label = (new \SplFileInfo($name !== '' ? $name : $file))->getFilename();
        $targetDir = rtrim($targetDir, '/');

        return match ($this->kind($file, $name)) {
            self::KIND_ZIP => $this->fromZip($file, $label, $targetDir, $pattern),
            self::KIND_TAR => $this->fromTar($file, $label, $targetDir, $pattern),
            self::KIND_GZ, self::KIND_TAR_GZ => $this->fromGzip($file, $label, $targetDir, $pattern),
            default => throw new ArchiveException(sprintf('"%s" is no ZIP, gzip or tar archive.', $label)),
        };
    }

    /**
     * @param string $file
     * @param string $label
     * @param string $targetDir
     * @param string $pattern
     * @return Unpacked
     */
    private function fromZip(string $file, string $label, string $targetDir, string $pattern): Unpacked
    {
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::RDONLY) !== true) {
            throw new ArchiveException(sprintf('The ZIP file "%s" cannot be opened (damaged?).', $label));
        }
        try {
            if ($zip->numFiles > $this->maxEntries) {
                throw new ArchiveException(
                    sprintf('"%s" holds more than %d files; it is not unpacked.', $label, $this->maxEntries)
                );
            }
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false || str_ends_with($stat['name'], '/') || $this->isZipLink($zip, $i)) {
                    continue;
                }
                $entries[$stat['name']] = $stat;
            }
            $entry = $this->choose(array_keys($entries), $label, $pattern);
            $stat = $entries[$entry];
            if ((int) ($stat['encryption_method'] ?? 0) !== 0) {
                throw new ArchiveException(sprintf('"%s" in "%s" is protected by a password.', $entry, $label));
            }
            if ((int) $stat['size'] > $this->maxBytes) {
                throw new ArchiveException($this->tooLarge($entry));
            }
            $stream = $zip->getStream($entry);
            if ($stream === false) {
                throw new ArchiveException(sprintf('"%s" in "%s" cannot be read.', $entry, $label));
            }
            $target = $this->target($targetDir, $entry);
            $this->copy($stream, $target, $entry);
        } finally {
            $zip->close();
        }

        return new Unpacked($target, $this->baseName($entry), $label, count($entries));
    }

    /**
     * A .gz file: one file, or a tar inside (tar.gz). The tar is unpacked next to the target first.
     *
     * @param string $file
     * @param string $label
     * @param string $targetDir
     * @param string $pattern
     * @return Unpacked
     */
    private function fromGzip(string $file, string $label, string $targetDir, string $pattern): Unpacked
    {
        $inner = (string) preg_replace('/\.(gz|tgz)$/i', '', $label);
        $inner = preg_match('/\.tgz$/i', $label) === 1 ? $inner . '.tar' : $inner;
        $plain = $targetDir . '/unpacked-gzip.bin';
        $stream = $this->files->fileOpen('compress.zlib://' . $file, 'rb');
        $this->copy($stream, $plain, $inner);
        if (substr($this->head($plain, 512), 257, 5) !== 'ustar' && !str_ends_with(strtolower($inner), '.tar')) {
            if (preg_match(self::ARCHIVE_NAME, $inner) === 1) {
                throw new ArchiveException(
                    sprintf('"%s" holds another archive (%s); it is not unpacked.', $label, $inner)
                );
            }
            $target = $this->target($targetDir, $inner);
            $this->files->rename($plain, $target);

            return new Unpacked($target, $this->baseName($inner), $label, 1);
        }
        try {
            return $this->fromTar($plain, $label, $targetDir, $pattern);
        } finally {
            if ($this->files->isExists($plain)) {
                $this->files->deleteFile($plain);
            }
        }
    }

    /**
     * A tar file (ustar, GNU long names, pax path records): regular files only.
     *
     * @param string $file
     * @param string $label
     * @param string $targetDir
     * @param string $pattern
     * @return Unpacked
     */
    private function fromTar(string $file, string $label, string $targetDir, string $pattern): Unpacked
    {
        $entries = $this->tarEntries($file, $label);
        $entry = $this->choose(array_keys($entries), $label, $pattern);
        [$offset, $size] = $entries[$entry];
        if ($size > $this->maxBytes) {
            throw new ArchiveException($this->tooLarge($entry));
        }
        $handle = $this->files->fileOpen($file, 'rb');
        try {
            $this->files->fileSeek($handle, $offset);
            $target = $this->target($targetDir, $entry);
            $out = $this->files->fileOpen($target, 'wb');
            try {
                for ($left = $size; $left > 0; $left -= strlen($chunk)) {
                    $chunk = $this->files->fileRead($handle, min(self::CHUNK, $left));
                    if ($chunk === '') {
                        throw new ArchiveException(sprintf('"%s" ends before "%s" does (damaged?).', $label, $entry));
                    }
                    $this->files->fileWrite($out, $chunk);
                }
            } finally {
                $this->files->fileClose($out);
            }
        } finally {
            $this->files->fileClose($handle);
        }

        return new Unpacked($target, $this->baseName($entry), $label, count($entries));
    }

    /**
     * @param string $file
     * @param string $label
     * @return array<string, array{0: int, 1: int}> name => [offset of the data, size]
     */
    private function tarEntries(string $file, string $label): array
    {
        $entries = [];
        $handle = $this->files->fileOpen($file, 'rb');
        try {
            $position = 0;
            $longName = null;
            $count = 0;
            while (true) {
                $header = $this->files->fileRead($handle, 512);
                if (strlen($header) < 512 || trim($header, "\0") === '') {
                    break;
                }
                if (++$count > $this->maxEntries) {
                    throw new ArchiveException(
                        sprintf('"%s" holds more than %d files; it is not unpacked.', $label, $this->maxEntries)
                    );
                }
                $size = (int) octdec(trim(substr($header, 124, 12), " \0"));
                $type = $header[156];
                $name = rtrim(substr($header, 0, 100), "\0");
                $prefix = substr($header, 257, 5) === 'ustar' ? rtrim(substr($header, 345, 155), "\0") : '';
                $name = $prefix !== '' ? $prefix . '/' . $name : $name;
                $data = $position + 512;
                $position = $data + (int) (ceil($size / 512) * 512);
                if ($type === 'L' || $type === 'x') {
                    // GNU long name, or a pax record: both name the next entry.
                    $body = $size > 0 && $size < 65536 ? $this->files->fileRead($handle, $size) : '';
                    $longName = $type === 'L'
                        ? rtrim($body, "\0")
                        : (preg_match('/(?:^|\n)\d+ path=([^\n]*)\n/', $body, $m) === 1 ? $m[1] : $longName);
                    $this->files->fileSeek($handle, $position);
                    continue;
                }
                if ($type === '0' || $type === "\0" || $type === '7') {
                    $entries[$longName ?? $name] = [$data, $size];
                }
                $longName = null;
                $this->files->fileSeek($handle, $position);
            }
        } finally {
            $this->files->fileClose($handle);
        }

        return $entries;
    }

    /**
     * The entry to read: the first by name that matches the pattern (file name or path, * and ?), else the first
     * CSV, Excel, ODS, JSON or XML file. Hidden files and macOS resource folders are left out.
     *
     * @param string[] $names
     * @param string $label
     * @param string $pattern
     * @return string
     */
    private function choose(array $names, string $label, string $pattern): string
    {
        $names = array_values(array_filter(
            $names,
            fn (string $name): bool => !str_starts_with($name, '__MACOSX/')
                && !str_starts_with($this->baseName($name), '.')
        ));
        $matches = array_values(array_filter(
            $names,
            fn (string $name): bool => $pattern !== ''
                ? fnmatch($pattern, $this->baseName($name), FNM_CASEFOLD) || fnmatch($pattern, $name, FNM_CASEFOLD)
                : in_array(strtolower((new \SplFileInfo($name))->getExtension()), self::READABLE, true)
        ));
        if ($matches === []) {
            $listed = array_slice($names, 0, 10);
            throw new ArchiveException(sprintf(
                '%s in "%s"%s It holds: %s',
                $pattern !== '' ? sprintf('No file matches "%s"', $pattern) : 'No CSV, Excel, ODS, JSON or XML file',
                $label,
                $pattern !== '' ? '.' : ' (set "File inside the archive").',
                $listed === [] ? 'no files.' : implode(', ', $listed) . (count($names) > 10 ? ' …' : '.')
            ));
        }
        usort($matches, 'strnatcasecmp');
        if (preg_match(self::ARCHIVE_NAME, $matches[0]) === 1) {
            throw new ArchiveException(
                sprintf(
                    '"%s" in "%s" is an archive itself; archives inside archives are not unpacked.',
                    $matches[0],
                    $label
                )
            );
        }

        return $matches[0];
    }

    /**
     * Copies a stream into a file, at most maxBytes.
     *
     * @param resource $stream closed here
     * @param string $target
     * @param string $entry for messages
     * @return void
     */
    private function copy($stream, string $target, string $entry): void
    {
        $out = $this->files->fileOpen($target, 'wb');
        $written = 0;
        $done = false;
        try {
            while (!$this->files->endOfFile($stream)) {
                $chunk = $this->files->fileRead($stream, self::CHUNK);
                $written += strlen($chunk);
                if ($written > $this->maxBytes) {
                    throw new ArchiveException($this->tooLarge($entry));
                }
                $this->files->fileWrite($out, $chunk);
            }
            $done = true;
        } catch (FileSystemException $exception) {
            throw new ArchiveException(sprintf('"%s" cannot be unpacked (damaged?).', $entry), 0, $exception);
        } finally {
            $this->files->fileClose($out);
            $this->files->fileClose($stream);
            if (!$done) {
                $this->files->deleteFile($target);
            }
        }
    }

    /**
     * @param string $targetDir
     * @param string $entry
     * @return string a fixed name: the entry's own name never becomes part of the path
     */
    private function target(string $targetDir, string $entry): string
    {
        $extension = strtolower((new \SplFileInfo($entry))->getExtension());

        return $targetDir . '/unpacked.' . (preg_match('/^[a-z0-9]{1,5}$/', $extension) === 1 ? $extension : 'dat');
    }

    /**
     * @param string $entry
     * @return string
     */
    private function tooLarge(string $entry): string
    {
        return sprintf(
            '"%s" unpacks to more than %d MB; it is not unpacked (a damaged file or a zip bomb).',
            $entry,
            (int) round($this->maxBytes / 1048576)
        );
    }

    /**
     * @param string $path
     * @return string the last part of an entry name
     */
    private function baseName(string $path): string
    {
        $parts = explode('/', str_replace('\\', '/', rtrim($path, '/')));

        return (string) end($parts);
    }

    /**
     * @param \ZipArchive $zip
     * @param int $index
     * @return bool a symbolic link (Unix attributes)
     */
    private function isZipLink(\ZipArchive $zip, int $index): bool
    {
        $system = 0;
        $attributes = 0;
        if (!$zip->getExternalAttributesIndex($index, $system, $attributes)) {
            return false;
        }

        return $system === \ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000;
    }

    /**
     * @param string $file
     * @return bool an Excel or OpenDocument file (a ZIP with their content parts)
     */
    private function isOfficeFile(string $file): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::RDONLY) !== true) {
            return false;
        }
        $office = $zip->locateName('[Content_Types].xml') !== false || $zip->locateName('mimetype') !== false;
        $zip->close();

        return $office;
    }

    /**
     * @param string $file
     * @param int $bytes
     * @return string
     */
    private function head(string $file, int $bytes): string
    {
        try {
            $handle = $this->files->fileOpen($file, 'rb');
        } catch (FileSystemException) {
            return '';
        }
        try {
            return $this->files->fileRead($handle, $bytes);
        } finally {
            $this->files->fileClose($handle);
        }
    }
}
