<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Pipeline\Fake;

use Iranimij\OpenImport\Model\Storage\ErrorLog;
use Iranimij\OpenImport\Model\Storage\ErrorRecord;

/**
 * Keeps errors in memory.
 */
class FakeErrorLog extends ErrorLog
{
    /**
     * @var ErrorRecord[]
     */
    public array $stored = [];

    public int $batches = 0;

    // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
    public function __construct()
    {
    }

    /**
     * @inheritDoc
     */
    public function add(int $runId, array $errors): void
    {
        $this->batches++;
        array_push($this->stored, ...$errors);
    }

    /**
     * @inheritDoc
     */
    public function count(int $runId): int
    {
        return count($this->stored);
    }

    /**
     * @inheritDoc
     */
    public function fetch(int $runId, int $limit = 100, int $offset = 0): array
    {
        return array_slice($this->stored, $offset, $limit);
    }
}
