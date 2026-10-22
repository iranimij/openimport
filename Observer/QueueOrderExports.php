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

/**
 * After an order is placed: queues the profiles that run when an order is placed, and order exports with the
 * "Also run after every order" switch, unless a run of that profile is already waiting (ten orders in a minute make
 * one run). Never breaks checkout.
 */
class QueueOrderExports implements ObserverInterface
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
        $this->queue->fire(TriggerEvents::ORDER_PLACED);
    }
}
