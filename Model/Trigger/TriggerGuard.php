<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Trigger;

/**
 * Knows whether this process is inside an OpenImport run. Products, stock and customers that a run writes start no
 * triggered runs: an import triggered by "a product is saved" would otherwise start itself again and again. Use a
 * job chain to run a profile after another.
 */
class TriggerGuard
{
    private int $depth = 0;

    /**
     * @return void
     */
    public function enter(): void
    {
        $this->depth++;
    }

    /**
     * @return void
     */
    public function leave(): void
    {
        $this->depth = max(0, $this->depth - 1);
    }

    /**
     * @return bool
     */
    public function inRun(): bool
    {
        return $this->depth > 0;
    }
}
