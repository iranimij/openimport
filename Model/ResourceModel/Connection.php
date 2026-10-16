<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\ResourceModel;

use Iranimij\OpenImport\Api\Data\ConnectionInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Table openimport_connection.
 */
class Connection extends AbstractDb
{
    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init('openimport_connection', ConnectionInterface::CONNECTION_ID);
    }

    /**
     * The id of the connection with this name (case-insensitive), or null.
     *
     * @param string $name
     * @return int|null
     */
    public function getIdByName(string $name): ?int
    {
        $connection = $this->getConnection();
        $id = $connection->fetchOne(
            $connection->select()
                ->from($this->getMainTable(), [ConnectionInterface::CONNECTION_ID])
                ->where('LOWER(name) = ?', mb_strtolower(trim($name)))
                ->limit(1)
        );

        return $id === false || $id === null ? null : (int) $id;
    }
}
