<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Connection;

use Iranimij\OpenImport\Api\ConnectionRepositoryInterface;
use Iranimij\OpenImport\Api\Data\ConnectionInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Connections in memory.
 */
class FakeConnectionRepository implements ConnectionRepositoryInterface
{
    /**
     * @var array<int, ConnectionInterface>
     */
    private array $items = [];

    /**
     * @param ConnectionInterface[] $connections with ids
     */
    public function __construct(array $connections = [])
    {
        foreach ($connections as $connection) {
            $this->items[(int) $connection->getConnectionId()] = $connection;
        }
    }

    /**
     * @inheritDoc
     */
    public function save(ConnectionInterface $connection): ConnectionInterface
    {
        if ($connection instanceof ConnectionStub && $connection->getConnectionId() === null) {
            $connection->setConnectionId(count($this->items) + 1);
        }
        $this->items[(int) $connection->getConnectionId()] = $connection;

        return $connection;
    }

    /**
     * @inheritDoc
     */
    public function getById(int $connectionId): ConnectionInterface
    {
        if (!isset($this->items[$connectionId])) {
            throw new NoSuchEntityException(__('Connection %1 does not exist.', $connectionId));
        }

        return $this->items[$connectionId];
    }

    /**
     * @inheritDoc
     */
    public function getByName(string $name): ConnectionInterface
    {
        foreach ($this->items as $item) {
            if (mb_strtolower($item->getName()) === mb_strtolower(trim($name))) {
                return $item;
            }
        }
        throw new NoSuchEntityException(__('There is no connection named "%1".', $name));
    }

    /**
     * @inheritDoc
     */
    public function getAll(): array
    {
        return array_values($this->items);
    }

    /**
     * @inheritDoc
     */
    public function delete(ConnectionInterface $connection): bool
    {
        unset($this->items[(int) $connection->getConnectionId()]);

        return true;
    }
}
