<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\ResourceModel\Connection;

use Iranimij\OpenImport\Model\Connection;
use Iranimij\OpenImport\Model\ResourceModel\Connection as ConnectionResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Saved connections.
 */
class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'connection_id';

    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init(Connection::class, ConnectionResource::class);
    }
}
