<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Archive;

use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File;

/**
 * Packs an export file into a ZIP, gzip or tar.gz file next to it (the export's "Compress" option).
 */
class Packer
{
    public const NONE = 'none';
    public const KINDS = [Unpacker::KIND_ZIP, Unpacker::KIND_GZ, Unpacker::KIND_TAR_GZ];

    private const CONTENT_TYPES = [
        Unpacker::KIND_ZIP => 'application/zip',
        Unpacker::KIND_GZ => 'application/gzip',
        Unpacker::KIND_TAR_GZ => 'application/gzip',
    ];

    private const CHUNK = 1048576;

    /**
     * @param File $files
     */
    public function __construct(
        private readonly File $files
    ) {
    }

    /**
     * @param string $path the written export file; removed once packed
     * @param string $kind one of self::KINDS
     * @return string the packed file: the same path plus .zip, .gz or .tar.gz
     * @throws ArchiveException
     */
    public function pack(string $path, string $kind): string
    {
        $name = (new \SplFileInfo($path))->getFilename();
        $target = $path . '.' . $kind;
        try {
            match ($kind) {
                Unpacker::KIND_ZIP => $this->zip($path, $name, $target),
                Unpacker::KIND_GZ => $this->gzip($path, $target, ''),
                Unpacker::KIND_TAR_GZ => $this->gzip($path, $target, $name),
                default => throw new ArchiveException(sprintf('"%s" is no archive kind OpenImport writes.', $kind)),
            };
        } catch (FileSystemException $exception) {
            throw new ArchiveException(sprintf('"%s" could not be packed: %s', $name, $exception->getMessage()));
        }
        $this->files->deleteFile($path);

        return $target;
    }

    /**
     * @param string $kind
     * @return string
     */
    public function contentType(string $kind): string
    {
        return self::CONTENT_TYPES[$kind] ?? 'application/octet-stream';
    }

    /**
     * @param string $path
     * @param string $name
     * @param string $target
     * @return void
     */
    private function zip(string $path, string $name, string $target): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($target, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true
            || !$zip->addFile($path, $name) || !$zip->close()) {
            throw new ArchiveException(sprintf('The ZIP file for "%s" could not be written.', $name));
        }
    }

    /**
     * @param string $path
     * @param string $target
     * @param string $tarName '' = plain gzip; else the name of the one file in a tar inside
     * @return void
     */
    private function gzip(string $path, string $target, string $tarName): void
    {
        $out = $this->files->fileOpen('compress.zlib://' . $target, 'wb6');
        $in = $this->files->fileOpen($path, 'rb');
        try {
            $size = 0;
            if ($tarName !== '') {
                $size = (int) ($this->files->stat($path)['size'] ?? 0);
                $this->files->fileWrite($out, $this->tarHeader($tarName, $size));
            }
            while (!$this->files->endOfFile($in)) {
                $this->files->fileWrite($out, $this->files->fileRead($in, self::CHUNK));
            }
            if ($tarName !== '') {
                // Data padded to whole blocks, then two empty blocks end the archive.
                $this->files->fileWrite($out, str_repeat("\0", (512 - $size % 512) % 512 + 1024));
            }
        } finally {
            $this->files->fileClose($in);
            $this->files->fileClose($out);
        }
    }

    /**
     * A ustar header for one regular file.
     *
     * @param string $name
     * @param int $size
     * @return string 512 bytes
     */
    private function tarHeader(string $name, int $size): string
    {
        if (strlen($name) > 100) {
            $extension = (new \SplFileInfo($name))->getExtension();
            $name = substr($name, 0, 99 - strlen($extension)) . '.' . $extension;
        }
        $header = str_pad($name, 100, "\0")
            . sprintf("%07o\0", 0644) . sprintf("%07o\0", 0) . sprintf("%07o\0", 0)
            . sprintf("%011o\0", $size) . sprintf("%011o\0", time())
            . str_repeat(' ', 8) . '0' . str_repeat("\0", 100)
            . "ustar\0" . '00' . str_pad('', 32, "\0") . str_pad('', 32, "\0")
            . sprintf("%07o\0", 0) . sprintf("%07o\0", 0) . str_repeat("\0", 155);
        $header = str_pad($header, 512, "\0");
        $checksum = array_sum(array_map('ord', str_split($header)));

        return substr_replace($header, sprintf("%06o\0 ", $checksum), 148, 8);
    }
}
