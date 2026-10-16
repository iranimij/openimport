<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Pipeline\Step;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;

/**
 * A step between mapping and core's import that works on batches of mapped records: downloads pictures, builds
 * configurable products, … Steps are shared services; begin() resets their per-run state. Extend AbstractRowStep
 * to implement only the hooks a step needs.
 *
 * Per run: begin() → columns() → for every batch observe() then apply() → finish() → summary().
 * - observe() sees every valid mapped record, also those the behaviour or "skip unchanged rows" leave out;
 * - apply() sees the records that go to core and may change or fail them;
 * - finish() may add records (a configurable parent built from its variants); they pass the behaviour and the
 *   delta check like file records, but not the steps.
 * The dry-run preview shows the mapped values, not the values steps wrote.
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
     * @throws PipelineException when the profile's settings for the step cannot work (the run fails with it)
     */
    public function begin(ProfileInterface $profile, string $behavior, bool $dryRun): bool;

    /**
     * The columns core receives: steps add the columns they fill and drop their own helper columns.
     *
     * @param string[] $columns the mapped columns (after earlier steps)
     * @return string[]
     * @throws PipelineException when the mapping lacks a column the step needs
     */
    public function columns(array $columns): array;

    /**
     * Every valid mapped record of a batch, before behaviour and delta (read only).
     *
     * @param StepRecord[] $records
     * @return void
     */
    public function observe(array $records): void;

    /**
     * Identifiers of rows that are in the file but go nowhere (filtered out, mapping errors) and of records other
     * steps added at the end: they are in the file too.
     *
     * @param string[] $identifiers
     * @return void
     */
    public function observeIdentifiers(array $identifiers): void;

    /**
     * Changes or fails the records of one batch that go to core.
     *
     * @param StepRecord[] $records
     * @param \Closure $tick call between slow operations (keeps the run's locks alive; may throw to cancel)
     * @return void
     */
    public function apply(array $records, \Closure $tick): void;

    /**
     * Records the step adds after the last batch.
     *
     * @param \Closure $tick
     * @return StepRecord[]
     */
    public function finish(\Closure $tick): array;

    /**
     * One line for the run message ("Pictures: 3 downloaded, 12 unchanged."), '' when there is nothing to say.
     *
     * @return string
     */
    public function summary(): string;
}
