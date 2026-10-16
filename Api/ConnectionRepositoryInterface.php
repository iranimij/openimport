<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Api;

use Iranimij\OpenImport\Api\Data\ConnectionInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Saved connections.
 *
 * @api
 */
interface ConnectionRepositoryInterface
{
    /**
     * @param ConnectionInterface $connection
     * @return ConnectionInterface
     * @throws CouldNotSaveException with every problem, one per line
     */
    public function save(ConnectionInterface $connection): ConnectionInterface;

    /**
     * @param int $connectionId
     * @return ConnectionInterface
     * @throws NoSuchEntityException
     */
    public function getById(int $connectionId): ConnectionInterface;

    /**
     * @param string $name
     * @return ConnectionInterface
     * @throws NoSuchEntityException
     */
    public function getByName(string $name): ConnectionInterface;

    /**
     * Every connection, by name.
     *
     * @return ConnectionInterface[]
     */
    public function getAll(): array;

    /**
     * Deletes a connection no profile uses.
     *
     * @param ConnectionInterface $connection
     * @return bool
     * @throws CouldNotDeleteException naming the profiles that still use it
     */
    public function delete(ConnectionInterface $connection): bool;
}
