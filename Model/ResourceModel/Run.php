<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\ResourceModel;

use Iranimij\OpenImport\Api\Data\RunInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Runs. Status changes that several processes race for (claiming a queued run) are single conditional UPDATEs.
 */
class Run extends AbstractDb
{
    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init('openimport_run', RunInterface::RUN_ID);
    }

    /**
     * Moves the oldest queued run to "running" and returns its id; null when nothing is queued. Safe when several
     * cron processes call it at once: only one UPDATE wins each run.
     *
     * @param int[] $excludeProfileIds profiles that already have a running run
     * @return int|null
     */
    public function claimNextQueued(array $excludeProfileIds = []): ?int
    {
        $connection = $this->getConnection();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $select = $connection->select()
                ->from($this->getMainTable(), [RunInterface::RUN_ID])
                ->where('status = ?', RunInterface::STATUS_QUEUED)
                ->order(RunInterface::RUN_ID . ' ASC')
                ->limit(1);
            if ($excludeProfileIds !== []) {
                $select->where('profile_id IS NULL OR profile_id NOT IN (?)', $excludeProfileIds);
            }
            $runId = $connection->fetchOne($select);
            if ($runId === false || $runId === null) {
                return null;
            }
            $claimed = $connection->update(
                $this->getMainTable(),
                [
                    'status' => RunInterface::STATUS_RUNNING,
                    'started_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                ],
                ['run_id = ?' => (int) $runId, 'status = ?' => RunInterface::STATUS_QUEUED]
            );
            if ($claimed === 1) {
                return (int) $runId;
            }
        }

        return null;
    }

    /**
     * Moves one queued run to "running"; false when another process was faster.
     *
     * @param int $runId
     * @return bool
     */
    public function claim(int $runId): bool
    {
        return $this->getConnection()->update(
            $this->getMainTable(),
            [
                'status' => RunInterface::STATUS_RUNNING,
                'started_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            ],
            ['run_id = ?' => $runId, 'status = ?' => RunInterface::STATUS_QUEUED]
        ) === 1;
    }

    /**
     * Cancels a run that has not started; false when it already started or does not exist.
     *
     * @param int $runId
     * @return bool
     */
    public function cancelQueued(int $runId): bool
    {
        return $this->getConnection()->update(
            $this->getMainTable(),
            [
                'status' => RunInterface::STATUS_CANCELLED,
                'message' => 'Cancelled before it started.',
                'finished_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            ],
            ['run_id = ?' => $runId, 'status = ?' => RunInterface::STATUS_QUEUED]
        ) === 1;
    }

    /**
     * Puts a claimed run back into the queue (its profile or entity is busy).
     *
     * @param int $runId
     * @return void
     */
    public function requeue(int $runId): void
    {
        $this->getConnection()->update(
            $this->getMainTable(),
            ['status' => RunInterface::STATUS_QUEUED, 'started_at' => null],
            ['run_id = ?' => $runId, 'status = ?' => RunInterface::STATUS_RUNNING]
        );
    }

    /**
     * Writes progress and counts of a running run without loading or saving the whole row.
     *
     * @param int $runId
     * @param int $rows rows processed so far
     * @param array<string, int> $counts count name => value
     * @return void
     */
    public function updateProgress(int $runId, int $rows, array $counts = []): void
    {
        $data = [RunInterface::PROGRESS_ROWS => max(0, $rows)];
        foreach ($counts as $name => $value) {
            if (in_array($name, RunInterface::COUNTS, true)) {
                $data['count_' . $name] = max(0, (int) $value);
            }
        }
        $this->getConnection()->update($this->getMainTable(), $data, ['run_id = ?' => $runId]);
    }

    /**
     * The current status straight from the database (to notice a cancel while running).
     *
     * @param int $runId
     * @return string|null
     */
    public function fetchStatus(int $runId): ?string
    {
        $connection = $this->getConnection();
        $status = $connection->fetchOne(
            $connection->select()->from($this->getMainTable(), ['status'])->where('run_id = ?', $runId)
        );

        return $status === false || $status === null ? null : (string) $status;
    }

    /**
     * Ids of profiles with a run in the given statuses.
     *
     * @param string[] $statuses
     * @return int[]
     */
    public function profilesWithStatus(array $statuses): array
    {
        $connection = $this->getConnection();

        return array_map('intval', $connection->fetchCol(
            $connection->select()
                ->distinct()
                ->from($this->getMainTable(), ['profile_id'])
                ->where('status IN (?)', $statuses)
                ->where('profile_id IS NOT NULL')
        ));
    }

    /**
     * Options of the profile's queued and running runs (which files "All matching files" already handed out).
     *
     * @param int $profileId
     * @return array<int, array<string, mixed>> run id => options
     */
    public function openRunOptions(int $profileId): array
    {
        $connection = $this->getConnection();
        $rows = $connection->fetchPairs(
            $connection->select()
                ->from($this->getMainTable(), ['run_id', 'options'])
                ->where('profile_id = ?', $profileId)
                ->where('status IN (?)', [RunInterface::STATUS_QUEUED, RunInterface::STATUS_RUNNING])
        );
        $options = [];
        foreach ($rows as $runId => $json) {
            $decoded = json_decode((string) $json, true);
            $options[(int) $runId] = is_array($decoded) ? $decoded : [];
        }

        return $options;
    }
}
