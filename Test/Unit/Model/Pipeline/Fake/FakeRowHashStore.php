<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Pipeline\Fake;

use Iranimij\OpenImport\Model\Storage\RowHashStore;

/**
 * Keeps delta hashes in memory.
 */
class FakeRowHashStore extends RowHashStore
{
    /**
     * @var array<int, array<string, string>>
     */
    public array $hashes = [];

    // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
    public function __construct()
    {
    }

    /**
     * @inheritDoc
     */
    public function fetch(int $profileId, array $identifiers): array
    {
        return array_intersect_key($this->hashes[$profileId] ?? [], array_flip($identifiers));
    }

    /**
     * @inheritDoc
     */
    public function save(int $profileId, array $hashes, ?int $runId = null): void
    {
        $this->hashes[$profileId] = $hashes + ($this->hashes[$profileId] ?? []);
    }

    /**
     * @inheritDoc
     */
    public function delete(int $profileId, array $identifiers): void
    {
        foreach ($identifiers as $identifier) {
            unset($this->hashes[$profileId][$identifier]);
        }
    }

    /**
     * @inheritDoc
     */
    public function clear(int $profileId): void
    {
        unset($this->hashes[$profileId]);
    }

    /**
     * @inheritDoc
     */
    public function count(int $profileId): int
    {
        return count($this->hashes[$profileId] ?? []);
    }
}
