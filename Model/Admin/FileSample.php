<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Admin;

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
     */
    public function __construct(
        private readonly ReaderFactory $readers
    ) {
    }

    /**
     * @param string $file absolute path
     * @param string $name original file name
     * @param string $format auto or a format
     * @param array<string, mixed> $options stored format options
     * @param int $rows
     * @return array{format: string, detected: array<string, string>, columns: string[],
     *               rows: array<int, array<string, string>>, sheets: string[]}
     * @throws ReaderException
     */
    public function read(string $file, string $name, string $format, array $options, int $rows = self::ROWS): array
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
