<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Admin;

use Iranimij\OpenImport\Model\Archive\Unpacker;
use Iranimij\OpenImport\Model\Reader\ReaderException;
use Iranimij\OpenImport\Model\Reader\ReaderFactory;
use Iranimij\OpenImport\Model\Reader\XlsxReader;

/**
 * The first rows of a file for the profile form, read on the server with the same streaming readers as a run (the
 * browser never parses files).
 */
class FileSample
{
    public const ROWS = 20;

    /**
     * @param ReaderFactory $readers
     * @param Unpacker $unpacker
     */
    public function __construct(
        private readonly ReaderFactory $readers,
        private readonly Unpacker $unpacker
    ) {
    }

    /**
     * @param string $file absolute path
     * @param string $name original file name
     * @param string $format auto or a format
     * @param array<string, mixed> $options stored format options
     * @param int $rows
     * @return array{format: string, detected: array<string, string>, columns: string[],
     *               rows: array<int, array<string, string>>, sheets: string[], inner: string}
     * @throws ReaderException
     */
    public function read(string $file, string $name, string $format, array $options, int $rows = self::ROWS): array
    {
        $inner = $this->unpacker->kindFor($file, $name, $options) === '' ? '' : null;

        return $this->unpacker->withFile(
            $file,
            $name,
            $options,
            fn (string $path, string $readName): array
                => $this->sample($path, $readName, $format, $options, $rows) + ['inner' => $inner ?? $readName]
        );
    }

    /**
     * @param string $file
     * @param string $name
     * @param string $format
     * @param array<string, mixed> $options
     * @param int $rows
     * @return array{format: string, detected: array<string, string>, columns: string[],
     *               rows: array<int, array<string, string>>, sheets: string[]}
     */
    private function sample(string $file, string $name, string $format, array $options, int $rows): array
    {
        $format = $format === '' || $format === 'auto' ? $this->readers->detect($file, $name) : $format;
        $reader = $this->readers->create($file, $format, $this->readers->options($options));
        $sample = [];
        foreach ($reader->rows() as $line => $row) {
            $sample[] = ['__row' => (string) $line] + $row;
            if (count($sample) >= $rows) {
                break;
            }
        }

        return [
            'format' => $format,
            'detected' => $reader->detected(),
            'columns' => $reader->columns(),
            'rows' => $sample,
            'sheets' => $reader instanceof XlsxReader ? $reader->sheetNames() : [],
        ];
    }
}
