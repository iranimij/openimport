<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\ViewModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Whether the shop has never run anything yet (the first-run notice on the profiles page).
 */
class FirstRun implements ArgumentInterface
{
    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @return bool
     */
    public function isFirstRun(): bool
    {
        $connection = $this->resource->getConnection();

        return (int) $connection->fetchOne(
            $connection->select()->from($this->resource->getTableName('openimport_run'), ['COUNT(*)'])
        ) === 0;
    }
}
