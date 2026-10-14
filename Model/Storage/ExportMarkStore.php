<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Storage;

use Magento\Framework\App\ResourceConnection;

/**
 * Which records a profile has exported (table openimport_export_mark): "only new" exports skip them, "new or
 * changed" exports compare the record's updated_at with the mark's time.
 */
class ExportMarkStore
{
    private const TABLE = 'openimport_export_mark';
    private const BATCH = 1000;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @param int $profileId
     * @param string $entityType
     * @param int[] $ids
     * @param int|null $runId
     * @return void
     */
    public function mark(int $profileId, string $entityType, array $ids, ?int $runId = null): void
    {
        $connection = $this->resource->getConnection();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), self::BATCH) as $chunk) {
            $rows = [];
            foreach ($chunk as $id) {
                $rows[] = ['profile_id' => $profileId, 'entity_type' => $entityType, 'entity_id' => $id, 'run_id' => $runId, 'exported_at' => $now];
            }
            $connection->insertOnDuplicate($this->resource->getTableName(self::TABLE), $rows, ['run_id', 'exported_at']);
        }
    }

    /**
     * @param int $profileId
     * @param string|null $entityType
     * @return int marks removed ("export everything again")
     */
    public function clear(int $profileId, ?string $entityType = null): int
    {
        $where = ['profile_id = ?' => $profileId];
        if ($entityType !== null) {
            $where['entity_type = ?'] = $entityType;
        }

        return $this->resource->getConnection()->delete($this->resource->getTableName(self::TABLE), $where);
    }

    /**
     * @param int $profileId
     * @param string $entityType
     * @return int
     */
    public function count(int $profileId, string $entityType): int
    {
        $connection = $this->resource->getConnection();

        return (int) $connection->fetchOne(
            $connection->select()->from($this->resource->getTableName(self::TABLE), ['COUNT(*)'])
                ->where('profile_id = ?', $profileId)->where('entity_type = ?', $entityType)
        );
    }
}
