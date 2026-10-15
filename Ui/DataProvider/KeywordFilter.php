<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Ui\DataProvider;

use Magento\Framework\Api\Filter;
use Magento\Framework\Data\Collection;
use Magento\Framework\View\Element\UiComponent\DataProvider\FilterApplierInterface;

/**
 * The grids' keyword search: a name search done by the grid collection (Magento's own search needs a full-text
 * index and silently does nothing without one).
 */
class KeywordFilter implements FilterApplierInterface
{
    /**
     * @param Collection $collection
     * @param Filter $filter
     * @return void
     */
    public function apply(Collection $collection, Filter $filter)
    {
        if (method_exists($collection, 'addFullTextFilter')) {
            $collection->addFullTextFilter((string) $filter->getValue());
        }
    }
}
