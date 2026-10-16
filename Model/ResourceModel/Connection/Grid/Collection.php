<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\ResourceModel\Connection\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/**
 * Connections grid: the keyword search looks in the connection name.
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
            $this->addFieldToFilter(
                'main_table.name',
                ['like' => '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value) . '%']
            );
        }

        return $this;
    }
}
