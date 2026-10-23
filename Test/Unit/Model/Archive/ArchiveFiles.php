<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Archive;

/**
 * Temporary archives for the archive tests, removed after each test.
 */
trait ArchiveFiles
{
    /**
     * @var string[]
     */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tempPaths) as $path) {
            if (is_dir($path)) {
                array_map('unlink', glob($path . '/*') ?: []);
                rmdir($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempPaths = [];
    }

    private function temp(string $content, string $extension): string
    {
        $file = sys_get_temp_dir() . '/openimport-archive-' . bin2hex(random_bytes(6)) . '.' . $extension;
        file_put_contents($file, $content);
        $this->tempPaths[] = $file;

        return $file;
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/openimport-unpack-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $this->tempPaths[] = $dir;

        return $dir;
    }

    /**
     * @param array<string, string> $entries name => content (a name ending in / is a folder)
     */
    private function zip(array $entries): string
    {
        $file = $this->temp('', 'zip');
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            if (str_ends_with($name, '/')) {
                $zip->addEmptyDir($name);
            } else {
                $zip->addFromString($name, $content);
            }
        }
        $zip->close();

        return $file;
    }

    private function gz(string $content): string
    {
        return $this->temp((string) gzencode($content), 'gz');
    }

    /**
     * A tar with ustar headers; names over 100 bytes get a GNU long-name entry. null content = a folder;
     * ['type' => '2', 'data' => ''] = a link (or any other type flag).
     *
     * @param array<string, string|array{type: string, data: string}|null> $entries
     */
    private function tar(array $entries): string
    {
        $out = '';
        foreach ($entries as $name => $content) {
            $type = '0';
            $data = is_string($content) ? $content : "";
            if ($content === null) {
                $type = '5';
                $data = '';
            } elseif (is_array($content)) {
                $type = $content['type'];
                $data = $content['data'];
            }
            if (strlen($name) > 100) {
                $out .= $this->tarEntry('././@LongLink', $name . "\0", 'L');
            }
            $out .= $this->tarEntry(strlen($name) > 100 ? substr($name, 0, 100) : $name, $data, $type);
        }

        return $this->temp($out . str_repeat("\0", 1024), 'tar');
    }

    private function tarEntry(string $name, string $data, string $type): string
    {
        $size = strlen($data);
        $header = str_pad($name, 100, "\0") . "0000644\0" . "0000000\0" . "0000000\0"
            . sprintf("%011o\0", $size) . sprintf("%011o\0", 0) . str_repeat(' ', 8) . $type
            . str_repeat("\0", 100) . "ustar\0" . '00' . str_repeat("\0", 32 + 32) . "0000000\0" . "0000000\0";
        $header = str_pad($header, 512, "\0");
        $checksum = array_sum(array_map('ord', str_split($header)));
        $header = substr_replace($header, sprintf("%06o\0 ", $checksum), 148, 8);

        return $header . $data . str_repeat("\0", (512 - $size % 512) % 512);
    }
}
