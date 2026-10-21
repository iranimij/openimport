<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Chain;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Model\Execution\RunQueue;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Job chains: after a live run, the profile's "then run" profile is queued as a run of its own, with this run as
 * its parent. The chain's profiles travel in the run options so a loop that appeared after the save is still
 * refused; a dry run starts nothing.
 */
class ChainStarter
{
    public const OPTION_CHAIN = 'chain';

    /**
     * @param ProfileRepositoryInterface $profiles
     * @param RunQueue $queue
     * @param ChainLinks $links
     */
    public function __construct(
        private readonly ProfileRepositoryInterface $profiles,
        private readonly RunQueue $queue,
        private readonly ChainLinks $links
    ) {
    }

    /**
     * @param ProfileInterface $profile the profile of the finished run
     * @param RunInterface $run the finished run
     * @return string a line for the run message, "" when nothing was started
     */
    public function after(ProfileInterface $profile, RunInterface $run): string
    {
        $nextId = $profile->getThenProfileId();
        if ($nextId === null || $run->isDryRun()
            || !in_array($run->getStatus(), RunInterface::FINAL_STATUSES, true)) {
            return '';
        }
        $succeeded = in_array($run->getStatus(), [RunInterface::STATUS_SUCCESS, RunInterface::STATUS_PARTIAL], true);
        if (!$succeeded && $profile->getThenWhen() !== ProfileInterface::THEN_ALWAYS) {
            return sprintf('Then: "%s" is not started (this run did not succeed).', $this->name($nextId));
        }
        if ($run->getStatus() === RunInterface::STATUS_CANCELLED) {
            return sprintf('Then: "%s" is not started (this run was cancelled).', $this->name($nextId));
        }
        $chain = array_map('intval', (array) ($run->getOptions()[self::OPTION_CHAIN] ?? []));
        $chain[] = (int) $profile->getProfileId();
        if (in_array($nextId, $chain, true) || count($chain) >= ChainLinks::MAX_LENGTH) {
            return sprintf('Then: "%s" is not started again (the chain would loop).', $this->name($nextId));
        }
        try {
            $next = $this->profiles->getById($nextId);
        } catch (NoSuchEntityException $exception) {
            return 'Then: the next profile was deleted; nothing started.';
        }
        if (!$next->isActive()) {
            return sprintf('Then: "%s" is inactive; nothing started.', $next->getName());
        }
        $queued = $this->queue->queue(
            $next,
            RunInterface::MODE_LIVE,
            RunInterface::TRIGGER_CHAIN,
            [self::OPTION_CHAIN => $chain],
            $run->getRequestedBy(),
            (int) $run->getRunId()
        );

        return sprintf('Then: "%s" queued as run %d.', $next->getName(), (int) $queued->getRunId());
    }

    /**
     * @param int $profileId
     * @return string
     */
    private function name(int $profileId): string
    {
        return $this->links->profile($profileId)['name'] ?? ('#' . $profileId);
    }
}
