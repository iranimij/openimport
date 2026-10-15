<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Observer;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Model\Entity\EntityRegistry;
use Iranimij\OpenImport\Model\Execution\RunQueue;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * After an order is placed: queues every order-export profile that asks for it ("Also run after every order"),
 * unless a run of that profile is already waiting (ten orders in a minute make one run). Never breaks checkout.
 */
class QueueOrderExports implements ObserverInterface
{
    /**
     * @param ProfileRepositoryInterface $profiles
     * @param SearchCriteriaBuilder $criteria
     * @param RunQueue $queue
     * @param ResourceConnection $resource
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ProfileRepositoryInterface $profiles,
        private readonly SearchCriteriaBuilder $criteria,
        private readonly RunQueue $queue,
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $criteria = $this->criteria
                ->addFilter(ProfileInterface::DIRECTION, ProfileInterface::DIRECTION_EXPORT)
                ->addFilter(ProfileInterface::ENTITY, EntityRegistry::ORDER)
                ->create();
            foreach ($this->profiles->getList($criteria)->getItems() as $profile) {
                $options = $profile->getExportOptions();
                if (empty($options['order']['on_place']) || $this->waiting((int) $profile->getProfileId())) {
                    continue;
                }
                $this->queue->queue($profile, RunInterface::MODE_LIVE, RunInterface::TRIGGER_EVENT, [], 'order placed');
            }
        } catch (\Throwable $exception) {
            $this->logger->error('OpenImport: could not queue order exports: ' . $exception->getMessage());
        }
    }

    /**
     * @param int $profileId
     * @return bool whether a run of the profile is queued already
     */
    private function waiting(int $profileId): bool
    {
        $connection = $this->resource->getConnection();

        return (bool) $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('openimport_run'), ['run_id'])
                ->where('profile_id = ?', $profileId)
                ->where('status = ?', RunInterface::STATUS_QUEUED)
                ->limit(1)
        );
    }
}
