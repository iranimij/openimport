<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Execution;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Api\RunRepositoryInterface;
use Iranimij\OpenImport\Model\Execution\Source\FileSelector;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\ResourceModel\Run as RunResource;

/**
 * "All matching files": a run of such a profile takes the first matching file by name and queues one run for each
 * other file, so every file has its own report and is moved after its own success. Files another queued or running
 * run of the profile already has are left to that run. With "stop at a failed file" the files after a failed one
 * wait for the next run.
 */
class FileBatch
{
    public const OPTION_FILE = 'remote_file';
    public const OPTION_BATCH = 'batch_of';
    public const ON_ERROR_STOP = 'stop';

    /**
     * @param SourceResolver $sources
     * @param RunQueue $queue
     * @param RunResource $runResource
     * @param RunRepositoryInterface $runs
     */
    public function __construct(
        private readonly SourceResolver $sources,
        private readonly RunQueue $queue,
        private readonly RunResource $runResource,
        private readonly RunRepositoryInterface $runs
    ) {
    }

    /**
     * Gives the run its file and queues the others; null when the profile reads one file.
     *
     * @param ProfileInterface $profile
     * @param RunInterface $run
     * @return string|null a line for the run message
     * @throws PipelineException when no file is left
     */
    public function start(ProfileInterface $profile, RunInterface $run): ?string
    {
        $options = $run->getOptions();
        if (($profile->getSourceConfig()['pick'] ?? '') !== FileSelector::PICK_ALL
            || !empty($options['file']) || !empty($options[self::OPTION_FILE])) {
            return null;
        }
        $source = $this->sources->source($profile);
        if (!$source instanceof MultiFileSourceInterface) {
            return null;
        }
        $taken = [];
        foreach ($this->runResource->openRunOptions((int) $profile->getProfileId()) as $runId => $open) {
            if ($runId !== (int) $run->getRunId() && is_string($open[self::OPTION_FILE] ?? null)) {
                $taken[$open[self::OPTION_FILE]] = true;
            }
        }
        $names = array_values(array_filter(
            $source->names($profile),
            static fn (string $name): bool => !isset($taken[$name])
        ));
        if ($names === []) {
            throw new PipelineException($taken === []
                ? sprintf('No file matches "%s".', (string) ($profile->getSourceConfig()['pattern'] ?? '*'))
                : 'Every matching file is already queued in another run of this profile.');
        }
        $rootId = (int) $run->getRunId();
        $run->setOptions($options + [self::OPTION_FILE => $names[0], self::OPTION_BATCH => $rootId]);
        $this->runs->save($run);
        $queued = [];
        foreach (array_slice($names, 1) as $name) {
            $queued[] = (int) $this->queue->queue(
                $profile,
                $run->isDryRun() ? RunInterface::MODE_DRY_RUN : RunInterface::MODE_LIVE,
                $run->getTrigger(),
                [self::OPTION_FILE => $name, self::OPTION_BATCH => $rootId]
                    + array_intersect_key($options, ['full' => true, 'confirmed' => true]),
                $run->getRequestedBy()
            )->getRunId();
        }

        return $queued === []
            ? sprintf('File 1 of 1: %s.', $names[0])
            : sprintf(
                'File 1 of %d: %s. The other files are queued as runs %s.',
                count($names),
                $names[0],
                implode(', ', $queued)
            );
    }

    /**
     * After a failed run of a batch with "stop at a failed file": the batch's queued runs are cancelled (their files
     * stay for the next run).
     *
     * @param ProfileInterface $profile
     * @param RunInterface $run the finished run
     * @return int runs cancelled
     */
    public function stopAfterFailure(ProfileInterface $profile, RunInterface $run): int
    {
        $batch = (int) ($run->getOptions()[self::OPTION_BATCH] ?? 0);
        if ($batch === 0 || ($profile->getSourceConfig()['all_on_error'] ?? '') !== self::ON_ERROR_STOP
            || !in_array($run->getStatus(), [RunInterface::STATUS_FAILED, RunInterface::STATUS_CANCELLED], true)) {
            return 0;
        }
        $cancelled = 0;
        foreach ($this->runResource->openRunOptions((int) $profile->getProfileId()) as $runId => $options) {
            if ((int) ($options[self::OPTION_BATCH] ?? 0) === $batch && $runId !== (int) $run->getRunId()
                && $this->runResource->cancelQueued($runId)) {
                $cancelled++;
            }
        }

        return $cancelled;
    }
}
