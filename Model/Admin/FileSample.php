<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Admin;

use Iranimij\OpenImport\Model\Archive\Unpacker;
use Iranimij\OpenImport\Model\Reader\OdsReader;
use Iranimij\OpenImport\Model\Reader\ReaderException;
use Iranimij\OpenImport\Model\Reader\ReaderFactory;
use Iranimij\OpenImport\Model\Reader\ReaderInterface;
use Iranimij\OpenImport\Model\Reader\XlsxReader;
use Iranimij\OpenImport\Model\Storage\TempFolder;
use Iranimij\OpenImport\Model\Xslt\XsltStep;

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
     * @param XsltStep $xslt
     * @param TempFolder $tempFolder
     */
    public function __construct(
        private readonly ReaderFactory $readers,
        private readonly Unpacker $unpacker,
        private readonly XsltStep $xslt,
        private readonly TempFolder $tempFolder
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
            function (string $path, string $readName) use ($format, $options, $rows, $inner): array {
                $sample = fn (string $file): array
                    => $this->sample($file, $readName, $format, $options, $rows) + ['inner' => $inner ?? $readName];
                if (!$this->xslt->applies($format, $readName, $options)) {
                    return $sample($path);
                }

                return $this->tempFolder->with('xslt', function (string $folder) use ($path, $options, $sample) {
                    $this->xslt->transform($path, (string) $options[XsltStep::OPTION], $folder . '/transformed.xml');

                    return $sample($folder . '/transformed.xml');
                });
            }
        );
    }

    /**
     * @param string $file
     * @param string $name
     * @param string $format
     * @param array<string, mixed> $options
     * @param int $rows
     * @return array{format: string, detected: array<string, string>, columns: string[],
     *               rows: array<int, array<string, string>>, sheets: string[], warnings: string[], empty: bool}
     */
    private function sample(string $file, string $name, string $format, array $options, int $rows): array
    {
        $format = $format === '' || $format === 'auto' ? $this->readers->detect($file, $name) : $format;
        $reader = $this->readers->create($file, $format, $this->readers->options($options));
        $sample = [];
        $warnings = [];
        foreach ($reader->rows() as $line => $row) {
            if (isset($row[ReaderInterface::EXTRA_CELLS])) {
                // phpcs:ignore Generic.Files.LineLength.TooLong
                $warnings[] = (string) __(
                    'Row %1 has more cells than the first row has columns (%2). A value probably contains the delimiter without quotes; the run reports this row as an error.',
                    $line,
                    $row[ReaderInterface::EXTRA_CELLS]
                );
                unset($row[ReaderInterface::EXTRA_CELLS]);
            }
            $sample[] = ['__row' => (string) $line] + $row;
            if (count($sample) >= $rows) {
                break;
            }
        }
        $columns = $reader->columns();

        return [
            'format' => $format,
            'detected' => $reader->detected(),
            'columns' => $columns,
            'rows' => $sample,
            'sheets' => $reader instanceof XlsxReader || $reader instanceof OdsReader ? $reader->sheetNames() : [],
            'warnings' => $warnings,
            'empty' => $columns === [],
        ];
    }
}
