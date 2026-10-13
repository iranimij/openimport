<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Admin;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\Filter\FilterException;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Pipeline\ProfileCompiler;
use Iranimij\OpenImport\Model\Reader\ReaderException;
use Iranimij\OpenImport\Model\Reader\ReaderFactory;

/**
 * The live preview of the mapping step: the first rows of the file as Magento will receive them, or why not.
 */
class MappingPreview
{
    public const ROWS = 5;

    /**
     * @param ReaderFactory $readers
     * @param ProfileCompiler $compiler
     */
    public function __construct(
        private readonly ReaderFactory $readers,
        private readonly ProfileCompiler $compiler
    ) {
    }

    /**
     * @param ProfileInterface $profile unsaved profile built from the form
     * @param string $file absolute path
     * @param string $name original file name
     * @return array{columns: string[], rows: array<int, array<string, mixed>>, problems: string[]}
     */
    public function preview(ProfileInterface $profile, string $file, string $name): array
    {
        try {
            $format = $profile->getFormat() === 'auto' ? $this->readers->detect($file, $name) : $profile->getFormat();
            $reader = $this->readers->create($file, $format, $this->readers->options($profile->getFormatOptions()));
            $columns = in_array($format, ['csv', 'xlsx'], true) ? $reader->columns() : null;
            [$mapping] = $this->compiler->mapping($profile, $columns);
            $filter = $this->compiler->filter($profile, $columns);
        } catch (ReaderException | PipelineException $exception) {
            return ['columns' => [], 'rows' => [], 'problems' => explode("\n", $exception->getMessage())];
        }
        $rows = [];
        foreach ($reader->rows() as $line => $row) {
            try {
                if (!$filter->keep($row)) {
                    continue;
                }
            } catch (FilterException $exception) {
                $rows[] = ['row' => $line, 'errors' => [$exception->getMessage()], 'values' => []];
                continue;
            }
            $mapped = $mapping->map($row);
            $errors = [];
            foreach ($mapped->errors as $error) {
                $errors[] = $error->field . ': ' . $error->message;
            }
            foreach ($mapped->isValid() ? $mapped->rows : [[]] as $index => $values) {
                $rows[] = ['row' => $line, 'store' => $values['store_view_code'] ?? '', 'errors' => $index === 0 ? $errors : [],
                    'values' => $values];
            }
            if (count(array_unique(array_column($rows, 'row'))) >= self::ROWS) {
                break;
            }
        }

        return ['columns' => $mapping->columns(), 'rows' => $rows, 'problems' => []];
    }
}
