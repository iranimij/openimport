<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory;

/**
 * Order statuses for the order export filter (code => label), without a "please select" entry.
 */
class OrderStatus implements OptionSourceInterface
{
    /**
     * @param CollectionFactory $statuses
     */
    public function __construct(
        private readonly CollectionFactory $statuses
    ) {
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->statuses->create()->toOptionArray() as $status) {
            $options[] = ['value' => (string) $status['value'], 'label' => (string) $status['label']];
        }

        return $options;
    }
}
