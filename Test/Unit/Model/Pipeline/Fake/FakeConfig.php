<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Pipeline\Fake;

use Iranimij\OpenImport\Model\Config;

/**
 * Settings for the pipeline tests.
 */
class FakeConfig extends Config
{
    // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
    public function __construct(
        public int $maxErrors = 10000,
        public int $threshold = 10
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getMaxErrorsPerRun(): int
    {
        return $this->maxErrors;
    }

    /**
     * @inheritDoc
     */
    public function getSafetyThresholdPercent(): int
    {
        return $this->threshold;
    }
}
