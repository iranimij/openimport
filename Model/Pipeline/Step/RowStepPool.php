<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Pipeline\Step;

use Iranimij\OpenImport\Api\Data\ProfileInterface;

/**
 * The row steps, in the order di.xml lists them (other modules may add their own).
 */
class RowStepPool
{
    /**
     * @param array<string, RowStepInterface> $steps
     */
    public function __construct(
        private readonly array $steps = []
    ) {
        foreach ($steps as $code => $step) {
            if (!$step instanceof RowStepInterface) {
                throw new \InvalidArgumentException(sprintf('Row step "%s" must implement RowStepInterface.', $code));
            }
        }
    }

    /**
     * The steps that take part in a run, already begun.
     *
     * @param ProfileInterface $profile
     * @param string $behavior
     * @param bool $dryRun
     * @return RowStepInterface[]
     */
    public function begin(ProfileInterface $profile, string $behavior, bool $dryRun): array
    {
        $active = [];
        foreach ($this->steps as $step) {
            if ($step->begin($profile, $behavior, $dryRun)) {
                $active[] = $step;
            }
        }

        return $active;
    }
}
