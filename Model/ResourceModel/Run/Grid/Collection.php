<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\ResourceModel\Run\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/**
 * Runs grid: the keyword search looks in the profile name and the file name (the table has no full-text index).
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
            $like = '%' . addcslashes($value, '%_\\') . '%';
            $this->addFieldToFilter(['main_table.profile_name', 'main_table.options'], [['like' => $like], ['like' => $like]]);
        }

        return $this;
    }
}
