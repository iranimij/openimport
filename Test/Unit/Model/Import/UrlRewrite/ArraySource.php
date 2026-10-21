<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Import\UrlRewrite;

use Magento\ImportExport\Model\Import\AbstractSource;

/**
 * Rows as an import source.
 */
class ArraySource extends AbstractSource
{
    /**
     * @param array<int, array<string, string>> $rows
     */
    public function __construct(private readonly array $rows)
    {
        $columns = [];
        foreach ($rows as $row) {
            $columns = array_unique(array_merge($columns, array_keys($row)));
        }
        parent::__construct(array_values($columns));
    }

    /**
     * @return string[]|false
     */
    protected function _getNextRow()
    {
        $row = $this->rows[$this->_key] ?? false;
        if ($row === false) {
            return false;
        }
        $values = [];
        foreach ($this->_colNames as $column) {
            $values[] = $row[$column] ?? '';
        }

        return $values;
    }
}
