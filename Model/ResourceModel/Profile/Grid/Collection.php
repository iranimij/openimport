<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\ResourceModel\Profile\Grid;

use Iranimij\OpenImport\Model\Config\Source\SourceOrDestinationFilter;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/**
 * Profiles grid: the keyword search looks in the profile name (the table has no full-text index); the
 * "Source / destination" filter knows import sources ("in:sftp") and export destinations ("out:email").
 */
class Collection extends SearchResult
{
    /**
     * @param string $value
     * @return $this
     */
    public function addFullTextFilter(string $value)
    {
        $value = trim($value);
        if ($value !== '') {
            $this->addFieldToFilter('main_table.name', ['like' => '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value) . '%']);
        }

        return $this;
    }

    /**
     * @param string|array<int, string> $field
     * @param null|string|array<string, mixed> $condition
     * @return $this
     */
    public function addFieldToFilter($field, $condition = null)
    {
        $value = is_array($condition) ? ($condition['eq'] ?? null) : $condition;
        if (in_array($field, ['source_type', 'main_table.source_type'], true) && is_string($value)) {
            $connection = $this->getConnection();
            if (str_starts_with($value, SourceOrDestinationFilter::EXPORT_PREFIX)) {
                // No destination stored means a download, the default.
                $destination = "COALESCE(CASE WHEN JSON_VALID(main_table.export_options) THEN JSON_UNQUOTE("
                    . "JSON_EXTRACT(main_table.export_options, '$.destination')) END, 'download')";
                $this->getSelect()
                    ->where('main_table.direction = ?', 'export')
                    ->where($destination . ' = ?', substr($value, strlen(SourceOrDestinationFilter::EXPORT_PREFIX)));

                return $this;
            }
            if (str_starts_with($value, SourceOrDestinationFilter::IMPORT_PREFIX)) {
                $this->getSelect()
                    ->where('main_table.direction = ?', 'import')
                    ->where(
                        $connection->quoteIdentifier('main_table.source_type') . ' = ?',
                        substr($value, strlen(SourceOrDestinationFilter::IMPORT_PREFIX))
                    );

                return $this;
            }
        }

        return parent::addFieldToFilter($field, $condition);
    }
}
