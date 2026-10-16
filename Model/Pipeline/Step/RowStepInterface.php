<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Pipeline\Step;

use Iranimij\OpenImport\Api\Data\ProfileInterface;

/**
 * A step between mapping and core's import that works on batches of mapped records: downloads pictures, builds
 * configurables, … Steps are shared services; begin() resets their per-run state.
 *
 * Steps see only records that passed the filters, the behaviour and the delta check. Rows they change go to core;
 * the dry-run preview still shows the mapped values. Steps change values of the mapped columns; they cannot add
 * columns (the spool's columns are fixed by the mapping).
 */
interface RowStepInterface
{
    /**
     * Prepares a run; false when the step has nothing to do for this profile (it is then not called again).
     *
     * @param ProfileInterface $profile
     * @param string $behavior the behaviour the run applies (ProfileInterface::BEHAVIOR_*)
     * @param bool $dryRun
     * @return bool
     */
    public function begin(ProfileInterface $profile, string $behavior, bool $dryRun): bool;

    /**
     * Changes or fails the records of one batch.
     *
     * @param StepRecord[] $records
     * @param \Closure $tick call between slow operations (keeps the run's locks alive; may throw to cancel)
     * @return void
     */
    public function apply(array $records, \Closure $tick): void;

    /**
     * One line for the run message ("Pictures: 3 downloaded, 12 unchanged."), '' when there is nothing to say.
     *
     * @return string
     */
    public function summary(): string;
}
