<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Observer;

use Iranimij\OpenImport\Model\Trigger\TriggerEvents;
use Iranimij\OpenImport\Model\Trigger\TriggerQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;

/**
 * After an order is saved with another status than it had: queues the profiles that run when an order changes
 * status (to one of their statuses). A new order is not a change: "An order is placed" covers it.
 */
class OrderStatusChanged implements ObserverInterface
{
    /**
     * @param TriggerQueue $queue
     */
    public function __construct(
        private readonly TriggerQueue $queue
    ) {
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof Order) {
            return;
        }
        $before = $order->getOrigData('status');
        $status = (string) $order->getStatus();
        if ($before === null || $status === '' || (string) $before === $status) {
            return;
        }
        $this->queue->fire(TriggerEvents::ORDER_STATUS_CHANGED, ['status' => $status]);
    }
}
