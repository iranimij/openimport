<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Execution;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Api\RunRepositoryInterface;
use Iranimij\OpenImport\Model\Export\ExportRunner;
use Iranimij\OpenImport\Model\Notification\RunMailer;
use Iranimij\OpenImport\Model\Pipeline\ImportRunner;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Pipeline\RunOutcome;
use Iranimij\OpenImport\Model\ResourceModel\Run as RunResource;
use Iranimij\OpenImport\Model\Storage\LockManager;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

/**
 * Executes one queued run: locks, source file, pipeline, final status. Called by the cron consumer and the CLI,
 * never by a web request.
 */
class RunProcessor
{
    /**
     * @param RunRepositoryInterface $runRepository
     * @param ProfileRepositoryInterface $profileRepository
     * @param RunResource $runResource
     * @param ImportRunner $importRunner
     * @param RunFiles $runFiles
     * @param LockManager $locks
     * @param SourceResolver $sources
     * @param LoggerInterface $logger
     * @param RunMailer $mailer
     * @param ExportRunner $exportRunner
     */
    public function __construct(
        private readonly RunRepositoryInterface $runRepository,
        private readonly ProfileRepositoryInterface $profileRepository,
        private readonly RunResource $runResource,
        private readonly ImportRunner $importRunner,
        private readonly RunFiles $runFiles,
        private readonly LockManager $locks,
        private readonly SourceResolver $sources,
        private readonly LoggerInterface $logger,
        private readonly RunMailer $mailer,
        private readonly ExportRunner $exportRunner
    ) {
    }

    /**
     * Claims the oldest queued run whose profile is not busy and runs it; null when the queue is empty.
     *
     * @return RunInterface|null
     */
    public function processNext(): ?RunInterface
    {
        $busy = $this->runResource->profilesWithStatus([RunInterface::STATUS_RUNNING]);
        $runId = $this->runResource->claimNextQueued($busy);

        return $runId === null ? null : $this->execute($runId);
    }

    /**
     * Runs a queued run now (CLI). A run that another process already started is left alone.
     *
     * @param int $runId
     * @return RunInterface
     * @throws NoSuchEntityException
     */
    public function process(int $runId): RunInterface
    {
        $run = $this->runRepository->getById($runId);
        if ($run->getStatus() !== RunInterface::STATUS_QUEUED || !$this->runResource->claim($runId)) {
            return $run;
        }

        return $this->execute($runId);
    }

    private function execute(int $runId): RunInterface
    {
        $run = $this->runRepository->getById($runId);
        $held = [];
        $profile = null;
        try {
            $profile = $this->profile($run);
            foreach ($this->lockNames($profile, $run) as $name) {
                if (!$this->locks->acquire($name, $runId)) {
                    // Another run holds it: back to the queue, picked up again on the next cron minute.
                    $this->runResource->requeue($runId);
                    return $this->runRepository->getById($runId);
                }
                $held[] = $name;
            }
            $outcome = $this->runProfile($profile, $run, $held);
        } catch (RunCancelledException $exception) {
            // The counts so far, as the run wrote them while reading.
            $counts = $this->runRepository->getById($runId)->getCounts();
            $outcome = new RunOutcome(RunInterface::STATUS_CANCELLED, $counts, $exception->getMessage());
        } catch (PipelineException $exception) {
            $outcome = new RunOutcome(RunInterface::STATUS_FAILED, $run->getCounts(), $exception->getMessage());
        } catch (\Throwable $exception) {
            $this->logger->error(sprintf('OpenImport run %d failed: %s', $runId, $exception->getMessage()), [
                'exception' => $exception,
            ]);
            $outcome = new RunOutcome(
                RunInterface::STATUS_FAILED,
                $run->getCounts(),
                'Unexpected error: ' . $exception->getMessage()
            );
        } finally {
            foreach ($held as $name) {
                $this->locks->release($name, $runId);
            }
        }

        $finished = $this->finish($runId, $outcome);
        if ($profile !== null) {
            $this->mailer->send($profile, $finished);
        }

        return $finished;
    }

    private function profile(RunInterface $run): ProfileInterface
    {
        if ($run->getProfileId() === null) {
            throw new PipelineException('The profile of this run was deleted.');
        }
        try {
            return $this->profileRepository->getById($run->getProfileId());
        } catch (NoSuchEntityException $exception) {
            throw new PipelineException('The profile of this run was deleted.', 0, $exception);
        }
    }

    /**
     * One run per profile at a time; one live import per core entity at a time.
     *
     * @param ProfileInterface $profile
     * @param RunInterface $run
     * @return string[]
     */
    private function lockNames(ProfileInterface $profile, RunInterface $run): array
    {
        $names = ['profile:' . $profile->getProfileId()];
        if (!$run->isDryRun() && $profile->getDirection() === ProfileInterface::DIRECTION_IMPORT) {
            $names[] = 'entity:' . $profile->getEntity();
        }

        return $names;
    }

    /**
     * @param ProfileInterface $profile
     * @param RunInterface $run
     * @param string[] $locks
     * @return RunOutcome
     */
    private function runProfile(ProfileInterface $profile, RunInterface $run, array $locks): RunOutcome
    {
        $runId = (int) $run->getRunId();
        $folder = $this->runFiles->folder($runId);
        $heartbeat = function (bool $canStop = false) use ($locks, $runId): void {
            foreach ($locks as $name) {
                $this->locks->heartbeat($name, $runId);
            }
            if ($canStop && $this->runFiles->cancelRequested($runId)) {
                throw new RunCancelledException('Cancelled while running, before anything was written or sent.');
            }
        };
        if ($profile->getDirection() === ProfileInterface::DIRECTION_EXPORT) {
            [$outcome, $path] = $this->exportRunner->run($profile, $run, $folder, $heartbeat);
            if ($path !== '') {
                $run->setOutputFile($this->runFiles->relative($path));
                $this->runRepository->save($run);
            }

            return $outcome;
        }
        $fetched = $this->sources->fetch($profile, $run, $folder);
        $staged = $this->runFiles->stageSource($runId, $fetched->path, $fetched->name);
        $run->setSourceFile($this->runFiles->relative($staged));
        if (empty($run->getOptions()['original_name']) && $fetched->name !== '') {
            // The run page and the download show the file by its own name (uploads set it when queued).
            $run->setOptions($run->getOptions() + ['original_name' => $fetched->name]);
        }
        $this->runRepository->save($run);
        $outcome = $this->importRunner->run($profile, $run, $staged, $folder, $fetched->name, $heartbeat);
        if ($fetched->reference !== '' && !$run->isDryRun() && empty($run->getOptions()['file'])
            && in_array($outcome->status, [RunInterface::STATUS_SUCCESS, RunInterface::STATUS_PARTIAL], true)) {
            $outcome = $this->afterSuccess($profile, $fetched, $outcome);
        }

        return $outcome;
    }

    /**
     * Moves or deletes the source file after a successful live run (profile's "after success"). A failure here does
     * not undo the import: the run stays successful and says what did not work.
     *
     * @param ProfileInterface $profile
     * @param FetchedFile $fetched
     * @param RunOutcome $outcome
     * @return RunOutcome
     */
    private function afterSuccess(ProfileInterface $profile, FetchedFile $fetched, RunOutcome $outcome): RunOutcome
    {
        try {
            $this->sources->source($profile)->finish($profile, $fetched->reference);
        } catch (PipelineException $exception) {
            return new RunOutcome(
                $outcome->status,
                $outcome->counts,
                rtrim($outcome->message) . ' The file was imported, but it could not be '
                . ($profile->getAfterSuccess() === 'delete' ? 'deleted' : 'moved') . ' afterwards: '
                . $exception->getMessage(),
                $outcome->historyId
            );
        }

        return $outcome;
    }

    private function finish(int $runId, RunOutcome $outcome): RunInterface
    {
        $run = $this->runRepository->getById($runId);
        foreach ($outcome->counts as $name => $value) {
            $run->setCount($name, $value);
        }
        $run->setStatus($outcome->status)
            ->setMessage($outcome->message)
            ->setPeakMemoryMb((int) ceil(memory_get_peak_usage(true) / 1048576))
            ->setFinishedAt((new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'));

        return $this->runRepository->save($run);
    }
}
