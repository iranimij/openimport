<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\ResourceModel\Profile\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/**
 * Profiles grid: the keyword search looks in the profile name (the table has no full-text index).
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
            $this->addFieldToFilter('main_table.name', ['like' => '%' . addcslashes($value, '%_\\') . '%']);
        }

        return $this;
    }
}
