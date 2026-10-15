<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Api;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Api\Data\RunStartResultInterface;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Api\RunManagementInterface;
use Iranimij\OpenImport\Api\RunRepositoryInterface;
use Iranimij\OpenImport\Model\Execution\RunQueue;
use Magento\Framework\Exception\LocalizedException;

/**
 * REST and service-contract entry to start runs. Upload profiles cannot start this way (the file comes from the
 * admin); every other source and every export can.
 */
class RunManagement implements RunManagementInterface
{
    /**
     * @param ProfileRepositoryInterface $profiles
     * @param RunRepositoryInterface $runs
     * @param RunQueue $queue
     * @param RunStartResultFactory $resultFactory
     */
    public function __construct(
        private readonly ProfileRepositoryInterface $profiles,
        private readonly RunRepositoryInterface $runs,
        private readonly RunQueue $queue,
        private readonly RunStartResultFactory $resultFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function start(int $profileId, string $mode = RunInterface::MODE_DRY_RUN): RunStartResultInterface
    {
        if (!in_array($mode, [RunInterface::MODE_DRY_RUN, RunInterface::MODE_LIVE], true)) {
            throw new LocalizedException(__('The mode must be "dry_run" or "live".'));
        }
        $profile = $this->profiles->getById($profileId);
        if ($profile->getDirection() === ProfileInterface::DIRECTION_IMPORT && $profile->getSourceType() === 'upload') {
            throw new LocalizedException(__(
                'Profile %1 reads an uploaded file. Give it a folder, URL, SFTP or FTP source to start it through the API.',
                $profileId
            ));
        }
        $run = $this->queue->queue($profile, $mode, RunInterface::TRIGGER_API, [], 'api');

        return $this->resultFactory->create(['run' => $run]);
    }

    /**
     * @inheritDoc
     */
    public function status(int $runId): RunStartResultInterface
    {
        return $this->resultFactory->create(['run' => $this->runs->getById($runId)]);
    }
}
