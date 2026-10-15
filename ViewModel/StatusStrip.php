<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\ViewModel;

use Iranimij\OpenImport\Api\Data\RunInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * The line above the profiles grid: how the last 24 hours went, what waits, and whether the cron is alive.
 */
class StatusStrip implements ArgumentInterface
{
    private const DAY = 86400;
    private const CRON_STALE = 600;

    /**
     * @param ResourceConnection $resource
     * @param UrlInterface $urlBuilder
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    /**
     * @return array<string, int> status => runs that finished (or wait) in the last 24 hours
     */
    public function lastDay(): array
    {
        $connection = $this->resource->getConnection();
        $since = gmdate('Y-m-d H:i:s', time() - self::DAY);
        $rows = $connection->fetchPairs(
            $connection->select()
                ->from($this->resource->getTableName('openimport_run'), ['status', 'COUNT(*)'])
                ->where('created_at >= ? OR status IN (\'queued\', \'running\')', $since)
                ->group('status')
        );

        return array_map('intval', $rows);
    }

    /**
     * @return int|null seconds since OpenImport's cron job last ran; null when it never ran
     */
    public function cronAge(): ?int
    {
        $connection = $this->resource->getConnection();
        $last = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('cron_schedule'), ['executed_at' => 'MAX(executed_at)'])
                ->where('job_code = ?', 'openimport_tick')
        );

        return $last ? max(0, time() - (int) strtotime((string) $last . ' UTC')) : null;
    }

    /**
     * @return bool whether the cron has not run OpenImport's job for 10 minutes
     */
    public function cronStale(): bool
    {
        $age = $this->cronAge();

        return $age === null || $age > self::CRON_STALE;
    }

    /**
     * @return int failed or partial runs in the last 24 hours
     */
    public function problems(): int
    {
        $counts = $this->lastDay();

        return ($counts[RunInterface::STATUS_FAILED] ?? 0) + ($counts[RunInterface::STATUS_PARTIAL] ?? 0);
    }

    /**
     * @return string
     */
    public function runsUrl(): string
    {
        return $this->urlBuilder->getUrl('openimport/run/index');
    }
}
