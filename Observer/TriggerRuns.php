<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Observer;

use Iranimij\OpenImport\Model\Trigger\TriggerQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Queues the profiles that run after a shop event. One class for several Magento events: di.xml names the trigger
 * event each instance stands for (product saved, stock changed, customer registered or saved).
 */
class TriggerRuns implements ObserverInterface
{
    /**
     * @param TriggerQueue $queue
     * @param string $event a TriggerEvents constant
     */
    public function __construct(
        private readonly TriggerQueue $queue,
        private readonly string $event
    ) {
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $this->queue->fire($this->event);
    }
}
