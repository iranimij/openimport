<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Pipeline\Fake;

use Iranimij\OpenImport\Model\ResourceModel\Run;

/**
 * Records progress updates.
 */
class FakeRunResource extends Run
{
    /**
     * @var array<int, array{0: int, 1: array<string, int>}>
     */
    public array $progress = [];

    // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
    public function __construct()
    {
    }

    /**
     * @inheritDoc
     */
    public function updateProgress(int $runId, int $rows, array $counts = []): void
    {
        $this->progress[] = [$rows, $counts];
    }
}
