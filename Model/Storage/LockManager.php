<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Storage;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\DuplicateException;

/**
 * Named locks in openimport_lock: one live run per profile ("profile:12") and one live import per core entity
 * ("entity:catalog_product"). A lock whose heartbeat is older than the stale time is taken over (the process holding
 * it died).
 */
class LockManager
{
    private const TABLE = 'openimport_lock';

    /**
     * @param ResourceConnection $resource
     * @param int $staleSeconds
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly int $staleSeconds = 3600
    ) {
    }

    /**
     * @param string $name
     * @param int $runId
     * @return bool true when this run now holds the lock
     */
    public function acquire(string $name, int $runId): bool
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $now = $this->now();
        try {
            $connection->insert($table, [
                'lock_name' => $name,
                'run_id' => $runId,
                'acquired_at' => $now,
                'heartbeat_at' => $now,
            ]);
            return true;
        } catch (DuplicateException $exception) {
            // Held already: take it over only when it is stale, or when this run holds it.
            $taken = $connection->update(
                $table,
                ['run_id' => $runId, 'acquired_at' => $now, 'heartbeat_at' => $now],
                [
                    'lock_name = ?' => $name,
                    '(' . $connection->quoteInto('run_id = ?', $runId) . ' OR '
                    . $connection->quoteInto('heartbeat_at < ?', $this->stale()) . ')',
                ]
            );
            return $taken === 1 || $this->holder($name) === $runId;
        }
    }

    /**
     * Records a one-time event ("schedule:12:202610130200"); true only for the first caller, ever.
     *
     * @param string $name
     * @return bool
     */
    public function claimOnce(string $name): bool
    {
        $now = $this->now();
        try {
            $this->resource->getConnection()->insert($this->resource->getTableName(self::TABLE), [
                'lock_name' => $name,
                'run_id' => null,
                'acquired_at' => $now,
                'heartbeat_at' => $now,
            ]);
            return true;
        } catch (DuplicateException $exception) {
            return false;
        }
    }

    /**
     * Removes one-time records and locks whose name starts with the prefix and that are older than the cut-off.
     *
     * @param string $prefix
     * @param \DateTimeImmutable $before
     * @return int removed rows
     */
    public function purge(string $prefix, \DateTimeImmutable $before): int
    {
        $connection = $this->resource->getConnection();

        return $connection->delete($this->resource->getTableName(self::TABLE), [
            'lock_name LIKE ?' => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix) . '%',
            'acquired_at < ?' => $before->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param string $name
     * @param int $runId
     * @return void
     */
    public function heartbeat(string $name, int $runId): void
    {
        $this->resource->getConnection()->update(
            $this->resource->getTableName(self::TABLE),
            ['heartbeat_at' => $this->now()],
            ['lock_name = ?' => $name, 'run_id = ?' => $runId]
        );
    }

    /**
     * @param string $name
     * @param int $runId
     * @return void
     */
    public function release(string $name, int $runId): void
    {
        $this->resource->getConnection()->delete(
            $this->resource->getTableName(self::TABLE),
            ['lock_name = ?' => $name, 'run_id = ?' => $runId]
        );
    }

    /**
     * The run holding a lock that is not stale, or null.
     *
     * @param string $name
     * @return int|null
     */
    public function holder(string $name): ?int
    {
        $connection = $this->resource->getConnection();
        $runId = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), ['run_id'])
                ->where('lock_name = ?', $name)
                ->where('heartbeat_at >= ?', $this->stale())
        );

        return $runId === false || $runId === null ? null : (int) $runId;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private function stale(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify(sprintf('-%d seconds', $this->staleSeconds))
            ->format('Y-m-d H:i:s');
    }
}
