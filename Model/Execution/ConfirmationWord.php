<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Execution;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Model\Pipeline\ImportRunner;

/**
 * The word a user types to start a run above the safety limit: the run message names it ("Type DISABLE to
 * confirm."); runs written before 1.3 fall back to the profile's behaviour.
 */
class ConfirmationWord
{
    /**
     * @param RunInterface $run
     * @param ProfileInterface $profile
     * @return string '' when the run needs no confirmation
     */
    public function forRun(RunInterface $run, ProfileInterface $profile): string
    {
        $message = $run->getMessage();
        $needs = $run->getStatus() === RunInterface::STATUS_NEEDS_CONFIRMATION
            || ($run->isDryRun() && str_contains($message, ImportRunner::CONFIRMATION_NOTE));
        if (!$needs) {
            return '';
        }
        if (preg_match('/Type ([A-Z][A-Z ]*[A-Z]) to confirm/', $message, $match) === 1) {
            return $match[1];
        }

        return $profile->getBehavior() === ProfileInterface::BEHAVIOR_REPLACE ? 'REPLACE' : 'DELETE';
    }
}
