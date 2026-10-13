<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Cron;

use Iranimij\OpenImport\Model\Config;
use Iranimij\OpenImport\Model\Execution\RunProcessor;
use Iranimij\OpenImport\Model\Schedule\Scheduler;
use Psr\Log\LoggerInterface;

/**
 * Every minute in OpenImport's own cron group: queue the due schedules, then run queued runs while the time budget
 * lasts (a run that has started always finishes).
 */
class Tick
{
    /**
     * @param Scheduler $scheduler
     * @param RunProcessor $processor
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly RunProcessor $processor,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $started = microtime(true);
        try {
            $this->scheduler->queueDue();
        } catch (\Throwable $exception) {
            $this->logger->error('OpenImport scheduler: ' . $exception->getMessage(), ['exception' => $exception]);
        }
        $budget = max(10, $this->config->getCronTimeBudget());
        while (microtime(true) - $started < $budget) {
            if ($this->processor->processNext() === null) {
                break;
            }
        }
    }
}
