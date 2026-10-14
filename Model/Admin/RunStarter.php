<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Admin;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Model\Execution\RunFiles;
use Iranimij\OpenImport\Model\Execution\RunQueue;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\Exception\LocalizedException;

/**
 * Queues runs from the admin. Upload profiles read the file uploaded last; "Run for real" after a dry run reads the
 * very file the dry run checked.
 */
class RunStarter
{
    /**
     * @param RunQueue $queue
     * @param UploadStore $uploads
     * @param RunFiles $runFiles
     * @param Session $authSession
     */
    public function __construct(
        private readonly RunQueue $queue,
        private readonly UploadStore $uploads,
        private readonly RunFiles $runFiles,
        private readonly Session $authSession
    ) {
    }

    /**
     * @param ProfileInterface $profile
     * @param string $mode
     * @param array<string, mixed> $options
     * @return RunInterface
     * @throws LocalizedException
     */
    public function start(ProfileInterface $profile, string $mode, array $options = []): RunInterface
    {
        if ($profile->getDirection() === ProfileInterface::DIRECTION_IMPORT && $profile->getSourceType() === 'upload') {
            $config = $profile->getSourceConfig();
            $path = $this->uploads->path((string) ($config['file'] ?? ''));
            if ($path === null) {
                throw new LocalizedException(__('Upload a file in the profile first (step 2, Where).'));
            }
            $options += ['file' => $path, 'original_name' => (string) ($config['file_name'] ?? 'upload')];
        }

        return $this->queue->queue($profile, $mode, RunInterface::TRIGGER_MANUAL, $options, $this->user());
    }

    /**
     * A live run of the file a dry run checked.
     *
     * @param ProfileInterface $profile
     * @param RunInterface $dryRun
     * @param bool $confirmed
     * @return RunInterface
     * @throws LocalizedException
     */
    public function liveFromDryRun(ProfileInterface $profile, RunInterface $dryRun, bool $confirmed = false): RunInterface
    {
        $options = [];
        if ($profile->getDirection() === ProfileInterface::DIRECTION_IMPORT) {
            $file = $dryRun->getSourceFile();
            if ($file === null) {
                throw new LocalizedException(__('The dry run has no file to import.'));
            }
            $options = [
                'file' => $this->runFiles->absolute($file),
                'original_name' => (string) ($dryRun->getOptions()['original_name'] ?? 'source'),
            ];
        }
        // An export reads Magento again: the live run delivers a fresh file, not the dry run's.
        if ($confirmed) {
            $options['confirmed'] = true;
        }
        if (!empty($dryRun->getOptions()['full'])) {
            $options['full'] = true;
        }

        return $this->queue->queue(
            $profile,
            RunInterface::MODE_LIVE,
            RunInterface::TRIGGER_MANUAL,
            $options,
            $this->user(),
            $dryRun->getRunId()
        );
    }

    private function user(): ?string
    {
        $user = $this->authSession->getUser();

        return $user === null ? null : (string) $user->getUserName();
    }
}
