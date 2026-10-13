<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Maintenance;

use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Model\Admin\UploadStore;
use Iranimij\OpenImport\Model\Config;
use Iranimij\OpenImport\Model\Execution\RunFiles;
use Iranimij\OpenImport\Model\JsonField;
use Iranimij\OpenImport\Model\Storage\LockManager;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;

/**
 * Keeps OpenImport's storage tidy:
 * - run folders of runs finished more than "keep run files for" days ago are removed (the run rows stay);
 * - uploaded files no profile uses any more are removed after two days;
 * - one-time schedule records older than two days are removed;
 * - runs still marked "running" whose process no longer holds their lock are marked failed.
 */
class Housekeeping
{
    private const UPLOAD_DAYS = 2;
    private const STALE_RUN_MINUTES = 90;

    /**
     * @param ResourceConnection $resource
     * @param Filesystem $filesystem
     * @param Config $config
     * @param LockManager $locks
     * @param JsonField $json
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Filesystem $filesystem,
        private readonly Config $config,
        private readonly LockManager $locks,
        private readonly JsonField $json
    ) {
    }

    /**
     * @param \DateTimeImmutable|null $now
     * @return array<string, int> what was removed or changed
     */
    public function run(?\DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('UTC'));

        return [
            'run_folders' => $this->runFolders($now->modify(sprintf('-%d days', max(1, $this->config->getKeepFilesDays())))),
            'uploads' => $this->uploads($now->modify(sprintf('-%d days', self::UPLOAD_DAYS))),
            'schedule_records' => $this->locks->purge('schedule:', $now->modify(sprintf('-%d days', self::UPLOAD_DAYS))),
            'stale_runs' => $this->staleRuns($now->modify(sprintf('-%d minutes', self::STALE_RUN_MINUTES))),
        ];
    }

    private function runFolders(\DateTimeImmutable $before): int
    {
        $connection = $this->resource->getConnection();
        $runIds = $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName('openimport_run'), ['run_id'])
                ->where('finished_at < ?', $before->format('Y-m-d H:i:s'))
                ->where('source_file IS NOT NULL OR output_file IS NOT NULL')
        );
        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $removed = 0;
        foreach ($runIds as $runId) {
            $folder = RunFiles::BASE . '/' . (int) $runId;
            if ($directory->isExist($folder)) {
                $directory->delete($folder);
                $removed++;
            }
            $connection->update(
                $this->resource->getTableName('openimport_run'),
                ['source_file' => null, 'output_file' => null],
                ['run_id = ?' => (int) $runId]
            );
        }

        return $removed;
    }

    private function uploads(\DateTimeImmutable $before): int
    {
        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        if (!$directory->isDirectory(UploadStore::FOLDER)) {
            return 0;
        }
        $connection = $this->resource->getConnection();
        $used = [];
        foreach ($connection->fetchCol($connection->select()->from(
            $this->resource->getTableName('openimport_profile'),
            ['source_config']
        )) as $config) {
            $file = $this->json->decode($config)['file'] ?? null;
            if (is_string($file)) {
                $used[$file] = true;
            }
        }
        $removed = 0;
        foreach ($directory->read(UploadStore::FOLDER) as $path) {
            $name = substr($path, strlen(UploadStore::FOLDER) + 1);
            $stat = $directory->stat($path);
            if (!isset($used[$name]) && (int) ($stat['mtime'] ?? 0) < $before->getTimestamp()) {
                $directory->delete($path);
                $removed++;
            }
        }

        return $removed;
    }

    private function staleRuns(\DateTimeImmutable $startedBefore): int
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('openimport_run');
        $runs = $connection->fetchPairs(
            $connection->select()
                ->from($table, ['run_id', 'profile_id'])
                ->where('status = ?', RunInterface::STATUS_RUNNING)
                ->where('started_at < ?', $startedBefore->format('Y-m-d H:i:s'))
        );
        $failed = 0;
        foreach ($runs as $runId => $profileId) {
            if ($profileId !== null && $this->locks->holder('profile:' . (int) $profileId) === (int) $runId) {
                continue;
            }
            $failed += $connection->update(
                $table,
                [
                    'status' => RunInterface::STATUS_FAILED,
                    'message' => 'The run stopped without finishing (its process ended). Start it again.',
                    'finished_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                ],
                ['run_id = ?' => (int) $runId, 'status = ?' => RunInterface::STATUS_RUNNING]
            );
        }

        return $failed;
    }
}
