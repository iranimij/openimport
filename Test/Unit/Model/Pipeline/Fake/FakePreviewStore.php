<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Pipeline\Fake;

use Iranimij\OpenImport\Model\Storage\PreviewRecord;
use Iranimij\OpenImport\Model\Storage\PreviewStore;

/**
 * Keeps preview records in memory.
 */
class FakePreviewStore extends PreviewStore
{
    /**
     * @var PreviewRecord[]
     */
    public array $stored = [];

    // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
    public function __construct()
    {
    }

    /**
     * @inheritDoc
     */
    public function add(int $runId, array $records): void
    {
        array_push($this->stored, ...$records);
    }

    /**
     * @return array<string, PreviewRecord> identifier => record
     */
    public function byIdentifier(): array
    {
        $records = [];
        foreach ($this->stored as $record) {
            $records[$record->identifier] = $record;
        }

        return $records;
    }
}
