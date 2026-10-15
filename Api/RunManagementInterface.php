<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Api;

/**
 * Starting profiles from other systems (REST: POST /V1/openimport/profiles/:profileId/runs) and asking how a run
 * went (GET /V1/openimport/runs/:runId). Runs are queued; OpenImport's cron runs them within a minute.
 *
 * @api
 */
interface RunManagementInterface
{
    /**
     * Queues a run of the profile with its configured source (or, for exports, destination).
     *
     * @param int $profileId
     * @param string $mode dry_run (default) or live
     * @return \Iranimij\OpenImport\Api\Data\RunStartResultInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function start(int $profileId, string $mode = 'dry_run'): Data\RunStartResultInterface;

    /**
     * @param int $runId
     * @return \Iranimij\OpenImport\Api\Data\RunStartResultInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function status(int $runId): Data\RunStartResultInterface;
}
