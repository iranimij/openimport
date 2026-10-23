<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\ResourceModel\Profile\Grid;

use Iranimij\OpenImport\Model\Config\Source\SourceOrDestinationFilter;
use Iranimij\OpenImport\Model\Security\Secrets;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/**
 * Profiles grid: the keyword search looks in the profile name (the table has no full-text index); the
 * "Source / destination" filter knows import sources ("in:sftp") and export destinations ("out:email"). The rows go to
 * the browser as they are, so stored secrets (encrypted passwords, keys, tokens) are taken out after loading.
 */
class Collection extends SearchResult
{
    /**
     * Columns that hold settings, with the encrypted secrets inside.
     */
    private const SETTINGS = ['source_config', 'export_options'];

    /**
     * @inheritDoc
     */
    protected function _afterLoad()
    {
        parent::_afterLoad();
        foreach ($this->getItems() as $item) {
            foreach (self::SETTINGS as $column) {
                $value = $item->getData($column);
                if (!is_string($value) || !str_contains($value, Secrets::KEY)) {
                    continue;
                }
                $settings = json_decode($value, true);
                if (!is_array($settings)) {
                    $item->setData($column, '{}');
                    continue;
                }
                unset($settings[Secrets::KEY]);
                $item->setData($column, (string) json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }

        return $this;
    }

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
            $where = $this->sourceOrDestination($value);
            if ($where !== null) {
                // One condition: the grid's filter pool joins the conditions a filter adds with OR.
                $this->getSelect()->where($where);

                return $this;
            }
        }

        return parent::addFieldToFilter($field, $condition);
    }

    /**
     * "in:sftp" → imports reading from SFTP, "out:email" → exports sent by e-mail (no destination: a download).
     *
     * @param string $value
     * @return string|null the SQL condition, null for any other value
     */
    private function sourceOrDestination(string $value): ?string
    {
        $connection = $this->getConnection();
        if (str_starts_with($value, SourceOrDestinationFilter::EXPORT_PREFIX)) {
            $destination = "COALESCE(CASE WHEN JSON_VALID(main_table.export_options) THEN JSON_UNQUOTE("
                . "JSON_EXTRACT(main_table.export_options, '$.destination')) END, 'download')";

            return $connection->quoteInto('main_table.direction = ?', 'export') . ' AND '
                . $connection->quoteInto(
                    $destination . ' = ?',
                    substr($value, strlen(SourceOrDestinationFilter::EXPORT_PREFIX))
                );
        }
        if (str_starts_with($value, SourceOrDestinationFilter::IMPORT_PREFIX)) {
            return $connection->quoteInto('main_table.direction = ?', 'import') . ' AND '
                . $connection->quoteInto(
                    'main_table.source_type = ?',
                    substr($value, strlen(SourceOrDestinationFilter::IMPORT_PREFIX))
                );
        }

        return null;
    }
}
