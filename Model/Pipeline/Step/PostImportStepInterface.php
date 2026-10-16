<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Pipeline\Step;

/**
 * A row step that changes records the file does not name, after Magento imported the file (products missing from
 * a complete list). The runner asks for the plan before the import, so the safety limit and the typed confirmation
 * cover it; a run with row errors never executes it (a row with an error may be a record the file names).
 */
interface PostImportStepInterface extends RowStepInterface
{
    /**
     * What the step would change, read after the last batch; null when nothing.
     *
     * @return PostImportPlan|null
     */
    public function plan(): ?PostImportPlan;

    /**
     * Carries out the plan after Magento imported the rows; a dry run only checks it.
     *
     * @param PostImportPlan $plan
     * @param bool $dryRun
     * @return PostImportOutcome
     */
    public function execute(PostImportPlan $plan, bool $dryRun): PostImportOutcome;

    /**
     * Name for the run message ("Products missing from the file").
     *
     * @return string
     */
    public function label(): string;
}
