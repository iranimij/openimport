<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model;

use Iranimij\OpenImport\Api\ConnectionRepositoryInterface;
use Iranimij\OpenImport\Api\Data\ConnectionInterface;
use Iranimij\OpenImport\Model\Connection\ConnectionUsage;
use Iranimij\OpenImport\Model\Connection\ConnectionValidator;
use Iranimij\OpenImport\Model\ResourceModel\Connection as ConnectionResource;
use Iranimij\OpenImport\Model\ResourceModel\Connection\CollectionFactory;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Saved connections.
 */
class ConnectionRepository implements ConnectionRepositoryInterface
{
    /**
     * @param ConnectionFactory $connectionFactory
     * @param ConnectionResource $resource
     * @param CollectionFactory $collectionFactory
     * @param ConnectionValidator $validator
     * @param ConnectionUsage $usage
     */
    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly ConnectionResource $resource,
        private readonly CollectionFactory $collectionFactory,
        private readonly ConnectionValidator $validator,
        private readonly ConnectionUsage $usage
    ) {
    }

    /**
     * @inheritDoc
     */
    public function save(ConnectionInterface $connection): ConnectionInterface
    {
        if (!$connection instanceof Connection) {
            throw new CouldNotSaveException(__('Unsupported connection object.'));
        }
        $problems = $this->validator->validate($connection);
        $sameName = $this->resource->getIdByName($connection->getName());
        if ($sameName !== null && $sameName !== $connection->getConnectionId()) {
            $problems[] = sprintf('A connection named "%s" already exists.', trim($connection->getName()));
        }
        if ($problems !== []) {
            throw new CouldNotSaveException(__(implode("\n", $problems)));
        }
        $connection->setName(trim($connection->getName()));
        try {
            $this->resource->save($connection);
        } catch (\Exception $exception) {
            throw new CouldNotSaveException(
                __('The connection could not be saved: %1', $exception->getMessage()),
                $exception
            );
        }

        return $this->getById((int) $connection->getId());
    }

    /**
     * @inheritDoc
     */
    public function getById(int $connectionId): ConnectionInterface
    {
        $connection = $this->connectionFactory->create();
        $this->resource->load($connection, $connectionId);
        if (!$connection->getId()) {
            throw new NoSuchEntityException(__('Connection %1 does not exist.', $connectionId));
        }

        return $connection;
    }

    /**
     * @inheritDoc
     */
    public function getByName(string $name): ConnectionInterface
    {
        $connectionId = $this->resource->getIdByName($name);
        if ($connectionId === null) {
            throw new NoSuchEntityException(__('There is no connection named "%1".', $name));
        }

        return $this->getById($connectionId);
    }

    /**
     * @inheritDoc
     */
    public function getAll(): array
    {
        $collection = $this->collectionFactory->create();
        $collection->setOrder(ConnectionInterface::NAME, 'ASC');
        $items = [];
        foreach ($collection->getItems() as $item) {
            if ($item instanceof ConnectionInterface) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @inheritDoc
     */
    public function delete(ConnectionInterface $connection): bool
    {
        if (!$connection instanceof Connection) {
            throw new CouldNotDeleteException(__('Unsupported connection object.'));
        }
        $profiles = $this->usage->profiles((int) $connection->getConnectionId());
        if ($profiles !== []) {
            throw new CouldNotDeleteException(__(
                'The connection "%1" is used by %2. Choose another login in these profiles first.',
                $connection->getName(),
                implode(', ', $profiles)
            ));
        }
        try {
            $this->resource->delete($connection);
        } catch (\Exception $exception) {
            throw new CouldNotDeleteException(
                __('The connection could not be deleted: %1', $exception->getMessage()),
                $exception
            );
        }

        return true;
    }
}
